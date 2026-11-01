<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\Roles;
use App\Support\Config;
use App\Support\Db;
use App\Support\Paths;
use Throwable;

/**
 * ตรวจช่องโหว่ของระบบให้ผู้ดูแล (หน้า /admin/security) — ทำงานแบบอ่านอย่างเดียว ไม่แก้ไฟล์ใด ๆ
 *
 * 1. ไฟล์: หา webshell / ไฟล์ PHP ที่ถูกฝาก (เช่นอัปโหลดผ่านช่องโหว่ PHP to shell) โดยเทียบทุกไฟล์กับ .git/index
 *    ไฟล์ที่ตรงกับที่ deploy มา = เชื่อถือได้ · ไฟล์ที่ถูกแก้ หรือไม่ได้มากับ git = ตรวจรูปแบบโค้ดอันตรายทั้งหมด
 *    vendor/ และ storage/cache (ไม่อยู่ใน git) ตรวจเฉพาะรูปแบบที่อันตรายชัดเจน · storage/uploads ห้ามมีโค้ดเลย
 * 2. ค่าตั้งของ PHP / แอป (debug, display_errors, allow_url_include, รุ่น PHP, https, install.lock, สิทธิ์เขียน)
 * 3. บัญชี: ผู้ดูแลทั้งหมด, บัญชีบุคลากรที่ยังใช้รหัสผ่านตัวอย่างหรือรหัสผ่านเดียวกับชื่อผู้ใช้, IP ที่เดารหัสผ่าน
 * 4. เว็บ: ขอ URL ของตัวเองว่าเปิด .git / config / log / รายชื่อไฟล์ ให้คนนอกเห็นหรือไม่ และมี header ป้องกันหรือไม่
 */
final class SecurityScanner
{
    public const SEVERITIES = ['high' => 'อันตราย', 'medium' => 'น่าสงสัย', 'low' => 'ควรปรับ', 'info' => 'ข้อมูล'];

    private const MAX_FINDINGS = 300;
    private const MAX_SCAN_BYTES = 3_000_000;
    private const MAX_UPLOAD_BYTES = 30_000_000;
    private const DEMO_PASSWORD = 'rvclearn2569';

    /** โฟลเดอร์ที่ไม่ต้องเดิน (ไม่ใช่โค้ดที่รันได้ หรือเป็นของ git เอง) */
    private const SKIP_DIRS = ['.git', 'node_modules', 'project', 'resources/design', 'storage/logs', 'storage/ai-context', 'storage/quarantine'];

    /** ไฟล์ที่ไม่อยู่ใน git โดยตั้งใจ (ดู .gitignore) */
    private const EXPECTED_UNTRACKED = ['config/config.php', 'storage/install.lock', 'composer.phar'];

    private const PHP_EXT = '/^(php\d?|phtml|phar|pht|phps|inc)$/';
    private const CODE_EXT = '/^(php\d?|phtml|phar|pht|phps|inc|latte|js|html?|htaccess)$/';

    /** @var list<array<string,mixed>> */
    private array $findings = [];
    /** @var list<string> */
    private array $passed = [];
    private bool $truncated = false;
    private int $files = 0;

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $started = microtime(true);
        $this->findings = $this->passed = [];
        $this->truncated = false;
        $this->files = 0;

        $git = $this->scanFiles(Paths::root());
        $this->checkConfig();
        $this->checkAccounts();
        $this->checkWeb();

        $order = array_flip(array_keys(self::SEVERITIES));
        usort($this->findings, static fn (array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);

        $counts = array_fill_keys(array_keys(self::SEVERITIES), 0);
        foreach ($this->findings as $f) {
            $counts[$f['severity']]++;
        }

        return [
            'ran_at' => date('Y-m-d H:i:s'),
            'seconds' => round(microtime(true) - $started, 1),
            'files' => $this->files,
            'git' => $git,
            'counts' => $counts,
            'findings' => $this->findings,
            'passed' => $this->passed,
            'truncated' => $this->truncated,
        ];
    }

    // ---------------------------------------------------------------- ไฟล์

    /** @return bool อ่าน .git/index ได้หรือไม่ (เทียบไฟล์กับที่ deploy มาได้) */
    private function scanFiles(string $root): bool
    {
        $index = GitIndex::read($root);
        $self = str_replace('\\', '/', (string) realpath(__FILE__));
        $seen = [];
        $before = count($this->findings);

        $dir = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($dir, function (\SplFileInfo $f) use ($root): bool {
            if (!$f->isDir()) {
                return true;
            }

            return !in_array($this->rel($root, $f->getPathname()), self::SKIP_DIRS, true);
        });

        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $this->files++;
            $path = $file->getPathname();
            $rel = $this->rel($root, $path);
            $seen[$rel] = true;
            if (str_replace('\\', '/', $path) === $self) {
                continue;
            }
            $this->checkFile($path, $rel, strtolower($file->getExtension()), $file->getSize(), $index);
        }

        if ($index === null) {
            $this->add('low', 'files', 'เทียบไฟล์กับโค้ดที่ deploy ไม่ได้',
                'อ่าน .git/index ไม่ได้ (ไม่มีโฟลเดอร์ .git หรือไม่มีสิทธิ์อ่าน) จึงตรวจได้แค่รูปแบบโค้ดอันตราย ไม่รู้ว่าไฟล์ไหนถูกแก้หรือถูกเพิ่มเข้ามา');
        } else {
            $missing = array_values(array_filter(array_keys($index), static function (string $p) use ($seen): bool {
                if (isset($seen[$p])) {
                    return false;
                }
                foreach (self::SKIP_DIRS as $skip) {
                    if (str_starts_with($p, $skip . '/')) {
                        return false; // อยู่ในโฟลเดอร์ที่ไม่ได้เดิน
                    }
                }

                return true;
            }));
            if ($missing) {
                $this->add('info', 'files', 'ไฟล์ของระบบหายไป ' . count($missing) . ' ไฟล์',
                    'ไฟล์ที่ควรมีตาม git แต่ไม่พบบนเซิร์ฟเวอร์: ' . implode(', ', array_slice($missing, 0, 10))
                    . (count($missing) > 10 ? ' …' : '') . ' — deploy ใหม่ (push ขึ้น main) จะได้ไฟล์คืน');
            }
        }

        if (count($this->findings) === $before) {
            $this->passed[] = $index !== null
                ? 'ไฟล์ทั้ง ' . number_format($this->files) . ' ไฟล์ตรงกับโค้ดที่ deploy และไม่พบโค้ดอันตราย'
                : 'ไม่พบรูปแบบโค้ดอันตรายใน ' . number_format($this->files) . ' ไฟล์';
        }

        return $index !== null;
    }

    /** @param array<string,string>|null $index */
    private function checkFile(string $path, string $rel, string $ext, int $size, ?array $index): void
    {
        $base = basename($rel);
        $isPhp = (bool) preg_match(self::PHP_EXT, $ext);
        $isCode = (bool) preg_match(self::CODE_EXT, $ext) || $base === '.htaccess' || $base === '.user.ini';
        $inUploads = str_starts_with($rel, 'storage/uploads/');
        $inVendor = str_starts_with($rel, 'vendor/');
        $inCache = str_starts_with($rel, 'storage/cache/');
        $tracked = $index !== null && isset($index[$rel]);

        // โฟลเดอร์อัปโหลด: ห้ามมีโค้ดเลย ทั้งนามสกุล PHP, .htaccess / .user.ini (เปิดให้รัน PHP ได้) และไฟล์ที่ซ่อน <?php ไว้ข้างใน
        if ($inUploads) {
            if ($isCode && $base !== '.gitkeep') {
                $this->add('high', 'files', 'ไฟล์โค้ดในโฟลเดอร์อัปโหลด',
                    'ระบบไม่เคยเก็บไฟล์ประเภทนี้ในโฟลเดอร์อัปโหลด น่าจะถูกฝากผ่านช่องโหว่อัปโหลด (PHP to shell)', $rel, quarantine: true);

                return;
            }
            if ($size <= self::MAX_UPLOAD_BYTES && $this->containsPhpTag($path)) {
                $this->add('high', 'files', 'ไฟล์อัปโหลดที่ซ่อนโค้ด PHP',
                    'ไฟล์ "' . $ext . '" นี้มีโค้ด <?php อยู่ข้างใน (เทคนิคซ่อน webshell ในรูปภาพหรือ PDF)', $rel, quarantine: true);
            }

            return;
        }

        if ($inVendor || $inCache) {
            // ไม่อยู่ใน git — ตรวจเฉพาะรูปแบบที่อันตรายชัดเจน เพื่อไม่ให้โค้ดของไลบรารีขึ้นเตือนเป็นแถว
            if ($isPhp || $base === '.htaccess' || $base === '.user.ini') {
                $hit = $this->match($path, $size, true);
                if ($hit !== null) {
                    $this->add('high', 'files', 'พบรูปแบบ webshell ในไฟล์ที่ไม่ได้มากับ git',
                        $hit['why'] . ($inVendor ? ' · ถ้าไม่แน่ใจ ลบโฟลเดอร์ vendor แล้วรัน composer install ใหม่' : ''),
                        $rel, $hit['line'], $hit['snippet'], quarantine: $inCache);
                }
                if ($base === '.htaccess' || $base === '.user.ini') {
                    $this->add('medium', 'files', 'พบไฟล์ ' . $base . ' ในโฟลเดอร์ไลบรารี',
                        'ไฟล์นี้เปลี่ยนการตั้งค่าเว็บเซิร์ฟเวอร์ได้ ตรวจว่ามาจากไลบรารีจริงหรือไม่', $rel);
                }
            }

            return;
        }

        if ($index === null) {
            if ($isCode) {
                $hit = $this->match($path, $size, false);
                if ($hit !== null) {
                    $this->add($hit['severity'], 'files', 'พบรูปแบบโค้ดที่ webshell มักใช้', $hit['why'], $rel, $hit['line'], $hit['snippet']);
                }
            }

            return;
        }

        if ($tracked) {
            if (GitIndex::blobHash($path) === $index[$rel]) {
                return; // ตรงกับที่ deploy มา
            }
            $hit = $isCode ? $this->match($path, $size, false) : null;
            $this->add($isCode ? ($hit ? 'high' : 'medium') : 'low', 'files', 'ไฟล์ของระบบถูกแก้ไข',
                ($hit ? $hit['why'] . ' · ' : '')
                . 'เนื้อหาไม่ตรงกับที่ deploy มา ถ้าไม่ได้แก้เอง ให้ deploy ใหม่ (push ขึ้น main) เพื่อเขียนทับ',
                $rel, $hit['line'] ?? null, $hit['snippet'] ?? null);

            return;
        }

        if (in_array($rel, self::EXPECTED_UNTRACKED, true) || $base === '.gitkeep' || $base === 'Thumbs.db' || $base === '.DS_Store') {
            return;
        }

        // ไม่ได้มากับ git: ไฟล์โค้ด = อันตราย (โดยเฉพาะใน public/ ที่เปิดจากเว็บได้ตรง ๆ)
        $hit = $isCode ? $this->match($path, $size, false) : null;
        $public = str_starts_with($rel, 'public/');
        $severity = $isCode ? ($hit || ($public && $isPhp) || $base === '.htaccess' || $base === '.user.ini' ? 'high' : 'medium') : 'low';
        $this->add($severity, 'files', $public && $isPhp ? 'ไฟล์ PHP แปลกปลอมในโฟลเดอร์เว็บ' : 'ไฟล์ที่ไม่ได้มากับการ deploy',
            ($hit ? $hit['why'] . ' · ' : '')
            . ($public && $isPhp ? 'เปิดจากเว็บได้โดยตรง ' : '')
            . 'ไฟล์นี้ไม่อยู่ใน git ถ้าไม่ได้วางไว้เอง ให้กักไฟล์ไว้ตรวจ',
            $rel, $hit['line'] ?? null, $hit['snippet'] ?? null, quarantine: true);
    }

    /**
     * รูปแบบโค้ดที่ webshell ใช้ · $strictOnly = เฉพาะที่แทบไม่มีโค้ดปกติใช้ (สำหรับ vendor/cache)
     * @return array{severity:string,why:string,line:int,snippet:string}|null
     */
    private function match(string $path, int $size, bool $strictOnly): ?array
    {
        if ($size > self::MAX_SCAN_BYTES) {
            return null;
        }
        $code = (string) @file_get_contents($path);
        $input = '\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)';
        $decode = '(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|hex2bin|convert_uudecode)';
        $rules = [
            ['high', '/\b(?:eval|assert)\s*\(\s*(?:@\s*)?(?:' . $decode . '|' . $input . ')/i', 'รันโค้ดที่ถอดรหัสหรือรับมาจากผู้ใช้ (eval/assert)'],
            ['high', '/\b(?:system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\(\s*(?:@\s*)?' . $input . '/i', 'รันคำสั่งระบบจากค่าที่ผู้ใช้ส่งมา'],
            ['high', '/`[^`\n]*' . $input . '[^`\n]*`/', 'รันคำสั่งระบบด้วย backtick จากค่าที่ผู้ใช้ส่งมา'],
            ['high', '/' . $input . '\s*\[[^\]]{1,40}\]\s*\(/', 'เรียกฟังก์ชันที่ชื่อมาจากค่าที่ผู้ใช้ส่งมา'],
            ['high', '/preg_replace\s*\(\s*([\'"]).{1,200}?\/[a-z]*e[a-z]*\1/i', 'preg_replace แบบ /e (รันโค้ดได้)'],
            ['high', '/\b(?:FilesMan|b374k|IndoXploit|WSO\s*\d|c99shell|r57shell|Shell\s+by\b)/i', 'ชื่อเครื่องมือ webshell ที่รู้จัก'],
            ['medium', '/\bcreate_function\s*\(/i', 'create_function (ถูกถอดออกจาก PHP 8 แล้ว โค้ดปกติไม่ใช้)'],
            ['medium', '/' . $decode . '\s*\(\s*[\'"][A-Za-z0-9+\/=\s]{300,}[\'"]/', 'ข้อความที่เข้ารหัสยาวผิดปกติ (โค้ดถูกซ่อน)'],
            ['medium', '/(?:chr\(\d+\)\s*\.\s*){10,}/i', 'ประกอบข้อความทีละตัวอักษรด้วย chr() (โค้ดถูกซ่อน)'],
            ['medium', '/(?:\\\\x[0-9a-f]{2}){30,}/i', 'ข้อความ hex ยาวผิดปกติ (โค้ดถูกซ่อน)'],
            ['medium', '/\bmove_uploaded_file\s*\(\s*\$_FILES\s*\[[^\]]+\]\s*\[\s*[\'"]tmp_name[\'"]\s*\]\s*,\s*\$_FILES/i', 'อัปโหลดไฟล์โดยใช้ชื่อที่ผู้ใช้ส่งมาตรง ๆ'],
        ];
        foreach ($rules as [$severity, $re, $why]) {
            if ($strictOnly && $severity !== 'high') {
                continue;
            }
            if (preg_match($re, $code, $m, PREG_OFFSET_CAPTURE)) {
                $offset = (int) $m[0][1];
                $line = substr_count($code, "\n", 0, $offset) + 1;
                $snippet = mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', substr($code, max(0, $offset - 40), 200))), 0, 160, '…', 'UTF-8');

                return ['severity' => $severity, 'why' => $why, 'line' => $line, 'snippet' => $snippet];
            }
        }

        return null;
    }

    private function containsPhpTag(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $tail = '';
        $found = false;
        while (!feof($h)) {
            $chunk = $tail . (string) fread($h, 1_048_576);
            if (stripos($chunk, '<?php') !== false) {
                $found = true;
                break;
            }
            $tail = substr($chunk, -4);
        }
        fclose($h);

        return $found;
    }

    // ---------------------------------------------------------------- ค่าตั้ง

    private function checkConfig(): void
    {
        $before = count($this->findings);

        if ((bool) $this->config->get('app.debug', false)) {
            $this->add('high', 'config', 'เปิดโหมด debug อยู่',
                'หน้าแจ้งข้อผิดพลาดจะแสดงรายละเอียดโค้ดและเส้นทางไฟล์ให้คนทั่วไปเห็น ตั้ง app.debug เป็น false ใน config/config.php');
        }
        if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || ini_get('display_errors') === 'stdout') {
            $this->add('low', 'config', 'PHP แสดงข้อผิดพลาดบนหน้าเว็บ (display_errors)',
                'ตั้ง display_errors = Off ใน php.ini ของเซิร์ฟเวอร์จริง ข้อผิดพลาดยังถูกเขียนลงไฟล์บันทึกตามปกติ');
        }
        if (filter_var(ini_get('allow_url_include'), FILTER_VALIDATE_BOOLEAN)) {
            $this->add('high', 'config', 'เปิด allow_url_include',
                'ทำให้ include ไฟล์จาก URL ภายนอกได้ (ช่องทางฝังโค้ดจากระยะไกล) ปิดใน php.ini');
        }
        if (filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN)) {
            $this->add('low', 'config', 'เซิร์ฟเวอร์บอกรุ่น PHP ใน header (expose_php)',
                'ผู้โจมตีใช้รุ่นที่เห็นเลือกช่องโหว่ได้ ตั้ง expose_php = Off ใน php.ini');
        }

        $eol = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31'];
        $minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (isset($eol[$minor]) && date('Y-m-d') > $eol[$minor]) {
            $this->add('medium', 'config', 'PHP ' . PHP_VERSION . ' หมดระยะแก้ช่องโหว่แล้ว',
                'PHP ' . $minor . ' ไม่ได้รับการแก้ช่องโหว่ตั้งแต่ ' . $eol[$minor] . ' ควรอัปเกรดเป็นรุ่นที่ยังได้รับการดูแล');
        } elseif (isset($eol[$minor])) {
            $this->passed[] = 'PHP ' . PHP_VERSION . ' ยังได้รับการแก้ช่องโหว่ถึง ' . $eol[$minor];
        }

        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $risky = array_diff(['system', 'shell_exec', 'passthru', 'popen', 'pcntl_exec'], $disabled);
        if ($risky) {
            $this->add('info', 'config', 'ยังเปิดฟังก์ชันรันคำสั่งระบบ',
                'ถ้า webshell หลุดเข้ามาจะใช้ ' . implode(', ', $risky) . ' สั่งเซิร์ฟเวอร์ได้ ปิดได้ด้วย disable_functions ใน php.ini '
                . '(ระบบนี้ใช้ exec สำหรับแปลงหน้า PDF ที่สแกน จึงควรเปิด exec ไว้)');
        }

        $url = (string) $this->config->get('app.url', '');
        if ($url !== '' && !str_starts_with($url, 'https://')) {
            $this->add('medium', 'config', 'ที่อยู่ของระบบไม่ได้ใช้ https',
                'รหัสผ่านและคุกกี้เข้าสู่ระบบจะถูกส่งแบบไม่เข้ารหัส ตั้ง app.url ให้เป็น https:// และเปิดใบรับรองบนเซิร์ฟเวอร์');
        }

        if (!is_file(Paths::lockFile())) {
            $this->add('high', 'config', 'ไม่พบ storage/install.lock',
                'ใครก็เปิด install.php ติดตั้งระบบทับได้ สร้างไฟล์นี้คืน (ติดตั้งใหม่หรือสร้างไฟล์เปล่า)');
        }

        foreach (['public' => 'public/', 'app' => 'app/'] as $dir => $label) {
            if (is_writable(Paths::root() . '/' . $dir)) {
                $this->add('low', 'config', 'เว็บเซิร์ฟเวอร์เขียนโฟลเดอร์ ' . $label . ' ได้',
                    'ถ้ามีช่องโหว่ ผู้โจมตีวางไฟล์ PHP ไว้ตรงนี้ได้ทันที ถ้าทำได้ให้ deploy ด้วยผู้ใช้อื่น และให้เว็บเซิร์ฟเวอร์เขียนได้เฉพาะ storage/');
            }
        }
        if (is_writable(Paths::configFile())) {
            $this->add('low', 'config', 'เว็บเซิร์ฟเวอร์แก้ไฟล์ config/config.php ได้',
                'ระบบเขียนไฟล์นี้เฉพาะตอนติดตั้ง หลังติดตั้งแล้วควรตั้งเป็นอ่านอย่างเดียว (chmod 440 หรือ 444)');
        }

        if (count($this->findings) === $before) {
            $this->passed[] = 'ค่าตั้งของ PHP และระบบไม่พบจุดเสี่ยง';
        }
    }

    // ---------------------------------------------------------------- บัญชี

    private function checkAccounts(): void
    {
        try {
            $admins = $this->db->all(
                "SELECT username, full_name, last_login_at, created_at FROM {users} WHERE role = 'admin' AND status = 'active' ORDER BY id"
            );
            $this->add('info', 'accounts', 'ผู้ดูแลระบบที่ใช้งานอยู่ ' . count($admins) . ' คน',
                'ตรวจว่ารู้จักทุกคน (ผู้โจมตีมักสร้างบัญชีผู้ดูแลไว้กลับเข้ามา): '
                . implode(', ', array_map(static fn (array $a): string => $a['username']
                    . ($a['full_name'] ? ' (' . $a['full_name'] . ')' : '')
                    . ' · สร้าง ' . substr((string) $a['created_at'], 0, 10), $admins)));

            $staff = $this->db->all(
                'SELECT username, role, password_hash FROM {users} WHERE status = \'active\' AND role IN (?, ?, ?) ORDER BY id LIMIT 400',
                Roles::STAFF
            );
            $demo = $same = [];
            foreach ($staff as $u) {
                $hash = (string) $u['password_hash'];
                if ($hash === '') {
                    continue;
                }
                if (password_verify(self::DEMO_PASSWORD, $hash)) {
                    $demo[] = $u['username'];
                } elseif (password_verify((string) $u['username'], $hash)) {
                    $same[] = $u['username'];
                }
            }
            if ($demo) {
                $this->add('high', 'accounts', 'บัญชีบุคลากรยังใช้รหัสผ่านของข้อมูลตัวอย่าง ' . count($demo) . ' บัญชี',
                    'รหัสผ่านนี้เขียนไว้ในเอกสารของระบบ ใครก็เข้าได้: ' . implode(', ', array_slice($demo, 0, 20))
                    . ' — ลบข้อมูลตัวอย่าง หรือรีเซ็ตรหัสผ่านที่หน้าผู้ใช้งาน');
            }
            if ($same) {
                $this->add('medium', 'accounts', 'บัญชีบุคลากรที่รหัสผ่านเหมือนชื่อผู้ใช้ ' . count($same) . ' บัญชี',
                    'เดาได้ง่ายมาก (มักเป็นบัญชีที่โอนจาก RMS แล้วไม่เคยเปลี่ยนรหัส): ' . implode(', ', array_slice($same, 0, 20))
                    . (count($same) > 20 ? ' …' : ''));
            }
            if (!$demo && !$same) {
                $this->passed[] = 'ไม่พบบัญชีบุคลากรที่ใช้รหัสผ่านตัวอย่างหรือรหัสผ่านเหมือนชื่อผู้ใช้ (' . count($staff) . ' บัญชี)';
            }

            $ips = $this->db->all(
                'SELECT ip_address, COUNT(*) AS n, COUNT(DISTINCT identifier) AS users FROM {login_attempts}
                 WHERE succeeded = 0 AND attempted_at >= ? GROUP BY ip_address HAVING n >= 20 ORDER BY n DESC LIMIT 10',
                [date('Y-m-d H:i:s', time() - 86400)]
            );
            if ($ips) {
                $this->add('medium', 'accounts', 'มีการเดารหัสผ่านใน 24 ชั่วโมงที่ผ่านมา',
                    'IP ที่เข้าสู่ระบบไม่สำเร็จตั้งแต่ 20 ครั้งขึ้นไป: ' . implode(', ', array_map(
                        static fn (array $r): string => $r['ip_address'] . ' (' . $r['n'] . ' ครั้ง, ' . $r['users'] . ' ชื่อผู้ใช้)', $ips))
                    . ' — ระบบล็อกชื่อผู้ใช้/IP ละ 15 นาทีหลังผิด 5 ครั้งอยู่แล้ว ถ้ายังมาเรื่อย ๆ ให้บล็อก IP ที่ไฟร์วอลล์');
            } else {
                $this->passed[] = 'ไม่มี IP ที่เดารหัสผ่านถี่ผิดปกติใน 24 ชั่วโมงที่ผ่านมา';
            }
        } catch (Throwable $e) {
            $this->add('low', 'accounts', 'ตรวจบัญชีผู้ใช้ไม่ได้', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- เว็บ

    private function checkWeb(): void
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($base === '' || !function_exists('curl_init')) {
            return;
        }

        $home = $this->fetch($base . '/');
        if ($home === null) {
            $this->add('info', 'web', 'ทดสอบจากภายนอกไม่ได้',
                'เซิร์ฟเวอร์เรียก ' . $base . ' ของตัวเองไม่ได้ (เครือข่ายอาจไม่อนุญาต) ลองเปิด ' . $base . '/.git/HEAD และ ' . $base . '/config/config.php จากเครื่องอื่นเอง ควรได้หน้า 404');

            return;
        }
        $before = count($this->findings);

        $probes = [
            ['/.git/HEAD', static fn (array $r): bool => $r['status'] === 200 && str_starts_with(ltrim($r['body']), 'ref:'), 'high',
                'เปิดโฟลเดอร์ .git จากเว็บได้', 'ใครก็ดาวน์โหลดซอร์สโค้ดทั้งหมดได้ ตรวจว่า .htaccess ที่รากโปรเจกต์ทำงาน (ต้องเปิด mod_rewrite)'],
            ['/config/config.php', static fn (array $r): bool => $r['status'] === 200 && str_contains($r['body'], '<?php'), 'high',
                'อ่านไฟล์ config.php จากเว็บได้', 'รหัสผ่านฐานข้อมูลและ app.key รั่ว เปลี่ยนรหัสทันทีและตรวจการตั้งค่า rewrite'],
            ['/storage/logs/app.log', static fn (array $r): bool => $r['status'] === 200 && $r['body'] !== '' && !str_contains($r['body'], '<html'), 'high',
                'เปิดไฟล์บันทึกจากเว็บได้', 'storage/ ต้องอยู่นอกรากเว็บ ตรวจ .htaccess ที่รากโปรเจกต์'],
            ['/composer.lock', static fn (array $r): bool => $r['status'] === 200 && str_contains($r['body'], '"packages"'), 'low',
                'เปิด composer.lock จากเว็บได้', 'ผู้โจมตีเห็นรุ่นของไลบรารีทั้งหมด'],
            ['/assets/', static fn (array $r): bool => $r['status'] === 200 && stripos($r['body'], 'Index of') !== false, 'medium',
                'เว็บแสดงรายชื่อไฟล์ในโฟลเดอร์', 'ใส่ Options -Indexes ใน .htaccess หรือการตั้งค่า Apache'],
        ];
        foreach ($probes as [$path, $isOpen, $severity, $title, $detail]) {
            $r = $this->fetch($base . $path);
            if ($r !== null && $isOpen($r)) {
                $this->add($severity, 'web', $title, $detail . ' (' . $base . $path . ')');
            }
        }

        $headers = $home['headers'];
        $missing = [];
        foreach (['x-content-type-options' => 'X-Content-Type-Options', 'x-frame-options' => 'X-Frame-Options'] as $k => $label) {
            if (!isset($headers[$k])) {
                $missing[] = $label;
            }
        }
        if (str_starts_with($base, 'https://') && !isset($headers['strict-transport-security'])) {
            $missing[] = 'Strict-Transport-Security';
        }
        if ($missing) {
            $this->add('low', 'web', 'ยังไม่มี header ป้องกัน: ' . implode(', ', $missing),
                'X-Content-Type-Options / X-Frame-Options ตั้งไว้ใน public/.htaccess แล้ว (ต้องเปิด mod_headers) · HSTS ตั้งที่ Apache ของโดเมน');
        }
        if (isset($headers['x-powered-by']) && preg_match('/PHP\/[\d.]+/i', $headers['x-powered-by'])) {
            // expose_php ถูกรายงานแล้วในหมวดค่าตั้ง ถ้าเป็นค่าจาก proxy ให้บอกตรงนี้
            if (!filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN)) {
                $this->add('low', 'web', 'เว็บเซิร์ฟเวอร์บอกรุ่น PHP ใน header', 'X-Powered-By: ' . $headers['x-powered-by']);
            }
        }

        if (count($this->findings) === $before) {
            $this->passed[] = 'เปิด .git, config, ไฟล์บันทึก และรายชื่อไฟล์จากเว็บไม่ได้ และมี header ป้องกันครบ';
        }
    }

    /** @return array{status:int,body:string,headers:array<string,string>}|null */
    private function fetch(string $url): ?array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_RANGE => '0-65535',
            CURLOPT_USERAGENT => 'RVC-Learn-SecurityCheck',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status === 0) {
            return null;
        }

        return ['status' => $status === 206 ? 200 : $status, 'body' => (string) $body, 'headers' => $headers];
    }

    // ---------------------------------------------------------------- ช่วย

    private function add(string $severity, string $group, string $title, string $detail, ?string $path = null,
        ?int $line = null, ?string $snippet = null, bool $quarantine = false): void
    {
        if (count($this->findings) >= self::MAX_FINDINGS) {
            $this->truncated = true;

            return;
        }
        $this->findings[] = [
            'severity' => $severity,
            'group' => $group,
            'title' => $title,
            'detail' => $detail,
            'path' => $path,
            'line' => $line,
            'snippet' => $snippet,
            'quarantine' => $quarantine,
        ];
    }

    private function rel(string $root, string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    }
}
