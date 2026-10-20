<?php

declare(strict_types=1);

namespace App\Integration;

use App\Auth\Roles;
use App\Auth\RvcOne;
use App\Auth\RvcOneException;
use App\Domain\InstitutionRepository;
use App\Domain\SettingsRepository;
use App\Support\Config;
use App\Support\Db;
use Throwable;

/**
 * ซิงค์รายชื่อผู้ใช้ (ครูและบุคลากร / นักเรียนนักศึกษา) จาก User-sync API ของ RVC-ONE
 *
 * - upsert ด้วย id ของ RVC-ONE (`users.oa_user_id`) — บัญชีที่มีอยู่แล้วแต่ยังไม่ผูก (เช่นโอนมาจาก RMS)
 *   จะถูกจับคู่ด้วยเลขบัตร / ชื่อผู้ใช้ / อีเมล เฉพาะเมื่อตรงกันแถวเดียว แล้วผูก id ไว้ แทนการสร้างซ้ำ
 * - รอบแรก (หรือสั่ง full) ดึงทั้งหมด รอบถัดไปส่ง since = server_time ของรอบก่อนที่จบครบทุกหน้า
 *   รอบที่ล้มกลางทางจะไม่ขยับ since — ผู้ใช้ที่ส่งซ้ำจะถูก upsert ทับแบบ idempotent
 * - status = suspended → ระงับบัญชี (ไม่ลบ) และเปิดคืนเองเฉพาะบัญชีที่การซิงค์นี้เป็นผู้ระงับ
 * - ไม่แตะบทบาท รหัสผ่าน หรือชื่อผู้ใช้ของบัญชีเดิม · บัญชีใหม่ได้รหัสผ่านสุ่ม (เข้าสู่ระบบผ่าน RVC-ONE)
 */
final class RvcOneUserSync
{
    /** since ของรอบถัดไป — ต่อท้ายด้วย :staff / :student เมื่อสั่งเฉพาะประเภท */
    public const SINCE = 'oa_sync_since';
    /** สรุปรอบล่าสุด (JSON) แสดงบนหน้าโอนข้อมูล */
    public const LAST = 'oa_sync_last';
    public const TYPES = ['staff', 'student'];
    private const PAGE = 500;
    /** กันวนไม่รู้จบถ้า API ส่ง has_more ผิดพลาด (500 × 400 = 200,000 คน) */
    private const MAX_PAGES = 400;
    private const LOCK = 'rvc_oa_user_sync';

    public function __construct(
        private readonly RvcOne $oa,
        private readonly Db $db,
        private readonly InstitutionRepository $institutions,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    /** ฐานข้อมูลมีคอลัมน์ oa_user_id แล้วหรือยัง (ไมเกรชันไม่รันเองตอน deploy) */
    public function ready(): bool
    {
        try {
            return $this->db->int(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$this->config->get('db.prefix', '') . 'users', 'oa_user_id']
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * สถานะสำหรับหน้าโอนข้อมูล
     *
     * @return array{ready:bool,since:?string,last:?array<string,mixed>,linked:int}
     */
    public function status(): array
    {
        $ready = $this->ready();
        $last = $this->settings->get(self::LAST); // type json — คืนเป็น array แล้ว
        $last = is_string($last) ? json_decode($last, true) : $last;

        return [
            'ready'  => $ready,
            'since'  => ((string) ($this->settings->get(self::SINCE) ?? '')) ?: null,
            'last'   => is_array($last) ? $last : null,
            'linked' => $ready ? $this->db->int('SELECT COUNT(*) FROM {users} WHERE oa_user_id IS NOT NULL') : 0,
        ];
    }

    /**
     * ซิงค์หนึ่งรอบ
     *
     * @param bool $full true = ดึงทั้งหมดใหม่ (ไม่ส่ง since)
     * @param ?string $type staff | student | null = ทุกประเภทที่แอปได้รับอนุญาต
     * @return array{created:int,updated:int,linked:int,suspended:int,reactivated:int,skipped:int,fetched:int,pages:int,since:?string,server_time:string}
     * @throws RvcOneException เมื่อเรียก API ไม่สำเร็จหรือฐานข้อมูลยังไม่พร้อม
     */
    public function run(bool $full = false, ?string $type = null): array
    {
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            throw new RvcOneException('invalid', 'ประเภทผู้ใช้ต้องเป็น staff หรือ student');
        }
        if (!$this->ready()) {
            throw new RvcOneException('config', 'ฐานข้อมูลยังไม่รองรับการซิงค์ผู้ใช้จาก RVC-ONE — กรุณารันไมเกรชันที่หน้า "ไมเกรชัน" (/admin/migrations) ก่อน');
        }
        if ((int) $this->db->value('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            throw new RvcOneException('unavailable', 'มีการซิงค์ผู้ใช้จาก RVC-ONE กำลังทำงานอยู่ กรุณารอให้เสร็จก่อน');
        }

        $sinceKey = self::SINCE . ($type !== null ? ':' . $type : '');
        $since = $full ? null : (((string) ($this->settings->get($sinceKey) ?? '')) ?: null);
        $started = date('Y-m-d H:i:s');

        try {
            $stats = $this->pages($type, $since);
            // บันทึก since ใหม่เมื่อครบทุกหน้าแล้วเท่านั้น และใช้เวลาของ RVC-ONE ไม่ใช่นาฬิกาเครื่องนี้
            if ($stats['server_time'] !== '') {
                $this->settings->set($sinceKey, $stats['server_time'], 'string', 'auth');
            }
            $this->remember(['ok' => true, 'at' => $started, 'full' => $since === null, 'type' => $type, 'stats' => $stats]);

            return $stats;
        } catch (Throwable $e) {
            $this->remember([
                'ok'      => false,
                'at'      => $started,
                'full'    => $since === null,
                'type'    => $type,
                'message' => $e instanceof RvcOneException ? $e->getMessage() : 'บันทึกข้อมูลไม่สำเร็จ ดูรายละเอียดที่หน้าบันทึกข้อขัดข้อง',
            ]);
            throw $e;
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * วนดึงทุกหน้าจนหมด แต่ละหน้าบันทึกใน transaction ของตัวเอง
     *
     * @return array{created:int,updated:int,linked:int,suspended:int,reactivated:int,skipped:int,fetched:int,pages:int,since:?string,server_time:string}
     */
    private function pages(?string $type, ?string $since): array
    {
        $first = ['type' => $type, 'since' => $since, 'limit' => self::PAGE];
        $params = $first;
        $restarted = false;
        $institutionId = $this->institutionId();
        $stats = ['created' => 0, 'updated' => 0, 'linked' => 0, 'suspended' => 0, 'reactivated' => 0, 'skipped' => 0, 'fetched' => 0, 'pages' => 0, 'since' => $since, 'server_time' => ''];

        while (true) {
            try {
                $page = $this->oa->users($params);
            } catch (RvcOneException $e) {
                // cursor หมดอายุ/ใช้ไม่ได้ → เริ่มรอบใหม่จากหน้าแรกหนึ่งครั้ง (upsert ซ้ำได้ ไม่เสียหาย)
                if ($e->error === 'bad_cursor' && isset($params['cursor']) && !$restarted) {
                    $restarted = true;
                    $params = $first;
                    $stats['server_time'] = '';
                    continue;
                }
                throw $e;
            }

            // ทุกหน้าในรอบเดียวกันมี server_time เท่ากัน — ยึดของหน้าแรก
            if ($stats['server_time'] === '') {
                $stats['server_time'] = $page['server_time'];
            }
            $stats['pages']++;
            $stats['fetched'] += count($page['users']);

            $this->db->transaction(function () use ($page, $institutionId, &$stats): void {
                foreach ($page['users'] as $u) {
                    $this->upsert($u, $institutionId, $stats);
                }
            });

            if (!$page['has_more']) {
                return $stats;
            }
            if ($page['next_cursor'] === null) {
                throw new RvcOneException('unavailable', 'RVC-ONE แจ้งว่ายังมีหน้าถัดไปแต่ไม่ส่ง cursor มา — ยังไม่บันทึกเวลาซิงค์ของรอบนี้');
            }
            if ($stats['pages'] >= self::MAX_PAGES) {
                throw new RvcOneException('unavailable', 'RVC-ONE ส่งข้อมูลมาเกินจำนวนหน้าที่รองรับ — หยุดไว้ก่อน');
            }
            $params = ['cursor' => $page['next_cursor'], 'limit' => self::PAGE];
        }
    }

    /** @param array<string,mixed> $u ผู้ใช้หนึ่งคนจาก API — ช่องเสริมทุกช่องอ่านแบบ optional */
    private function upsert(array $u, int $institutionId, array &$stats): void
    {
        $oaId = is_numeric($u['id'] ?? null) ? (int) $u['id'] : 0;
        $kind = self::str($u['user_type'] ?? '');
        $fullName = mb_substr(self::str(self::str($u['first_name'] ?? '') . ' ' . self::str($u['last_name'] ?? '')), 0, 150);
        if ($oaId <= 0 || !in_array($kind, self::TYPES, true) || $fullName === '') {
            $stats['skipped']++;

            return;
        }
        $isStudent = $kind === 'student';
        $suspended = self::str($u['status'] ?? '') === 'suspended';
        $department = self::str($u['department'] ?? '');
        $email = self::str($u['email'] ?? '') ?: self::str($u['google_email'] ?? '');

        $user = $this->find($oaId, $u, $isStudent);

        if ($user === null) {
            if ($suspended) {
                $stats['skipped']++; // ไม่สร้างบัญชีใหม่ให้คนที่ถูกระงับอยู่แล้ว

                return;
            }
            $username = $this->freeUsername(self::str($u['username'] ?? ''), $oaId, $institutionId);
            if ($username === null) {
                $stats['skipped']++;

                return;
            }
            $this->db->insert('users', [
                'username'       => $username,
                'email'          => $this->freeEmail($email, null),
                // RVC-ONE ไม่ส่งรหัสผ่านมา — ตั้งค่าสุ่มที่ไม่มีใครรู้ ผู้ใช้เข้าสู่ระบบผ่าน RVC-ONE (หรือแอดมินรีเซ็ตให้)
                'password_hash'  => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'full_name'      => $fullName,
                'role'           => $isStudent ? Roles::STUDENT : Roles::TEACHER,
                'status'         => 'active',
                'institution_id' => $institutionId,
                'subject_area'   => !$isStudent && $department !== '' ? mb_substr($department, 0, 191) : null,
                'oa_user_id'     => $oaId,
            ]);
            $stats['created']++;

            return;
        }

        // ไม่แตะ role / username / password_hash ที่แอดมินหรือผู้ใช้ตั้งเองแล้ว
        $fields = ['oa_user_id' => $oaId, 'full_name' => $fullName];
        if (($mail = $this->freeEmail($email, (int) $user['id'])) !== null) {
            $fields['email'] = $mail;
        }
        if ($user['institution_id'] === null) {
            $fields['institution_id'] = $institutionId;
        }
        if (!$isStudent && $department !== '' && trim((string) $user['subject_area']) === '') {
            $fields['subject_area'] = mb_substr($department, 0, 191);
        }
        if ($suspended && $user['status'] === 'active' && $user['role'] !== Roles::ADMIN) {
            // ไม่ระงับผู้ดูแลระบบอัตโนมัติ กันวิทยาลัยถูกล็อกออกจากระบบทั้งหมด
            $fields['status'] = 'suspended';
            $fields['oa_deactivated_at'] = date('Y-m-d H:i:s');
            $stats['suspended']++;
        } elseif (!$suspended && $user['status'] === 'suspended' && $user['oa_deactivated_at'] !== null) {
            // เปิดคืนเฉพาะคนที่การซิงค์นี้เป็นผู้ระงับ — คนที่แอดมินระงับเองยังคงถูกระงับ
            $fields['status'] = 'active';
            $fields['oa_deactivated_at'] = null;
            $stats['reactivated']++;
        }
        if ($user['oa_user_id'] === null) {
            $stats['linked']++;
        }
        $this->db->update('users', $fields, ['id' => (int) $user['id']]);
        $stats['updated']++;
    }

    /**
     * หาบัญชีของผู้ใช้คนนี้ — ด้วย oa_user_id ก่อน แล้วจึงจับคู่บัญชีที่ยังไม่ผูก (เหมือน Auth::findBySso)
     * ด้วยเลขบัตร → ชื่อผู้ใช้ → อีเมล เฉพาะประเภทเดียวกันและเฉพาะเมื่อตรงกันแถวเดียว
     *
     * @param array<string,mixed> $u
     * @return ?array<string,mixed>
     */
    private function find(int $oaId, array $u, bool $isStudent): ?array
    {
        $columns = 'id, role, status, institution_id, subject_area, oa_user_id, oa_deactivated_at';
        $row = $this->db->first("SELECT {$columns} FROM {users} WHERE oa_user_id = ?", [$oaId]);
        if ($row !== null) {
            return $row;
        }

        $nationalId = preg_replace('/\D/', '', (string) ($u['national_id'] ?? '')) ?? '';
        $username = self::str($u['username'] ?? '');
        $email = self::str($u['email'] ?? '') ?: self::str($u['google_email'] ?? '');

        $lookups = [];
        if (!$isStudent && strlen($nationalId) === 13) {
            $lookups[] = ['(people_id = ? OR username = ?)', [$nationalId, $nationalId]];
        }
        if ($username !== '') {
            $lookups[] = ['username = ?', [$username]];
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $lookups[] = ['LOWER(email) = LOWER(?)', [$email]];
        }

        $role = $isStudent ? "role = 'student'" : "role <> 'student'";
        foreach ($lookups as [$where, $params]) {
            $rows = $this->db->all(
                "SELECT {$columns} FROM {users} WHERE {$where} AND {$role} AND oa_user_id IS NULL LIMIT 2",
                $params
            );
            if (count($rows) === 1) {
                return $rows[0];
            }
        }

        return null;
    }

    /** ชื่อผู้ใช้ของบัญชีใหม่ — ใช้ username จาก RVC-ONE ถ้าว่าง ไม่งั้น oa{id} · null ถ้าชนทั้งคู่ */
    private function freeUsername(string $wanted, int $oaId, int $institutionId): ?string
    {
        $candidates = [];
        if ($wanted !== '' && mb_strlen($wanted) <= 64 && preg_match('/\s/u', $wanted) !== 1) {
            $candidates[] = $wanted;
        }
        $candidates[] = 'oa' . $oaId;

        foreach ($candidates as $name) {
            if ($this->db->int('SELECT COUNT(*) FROM {users} WHERE institution_id = ? AND username = ?', [$institutionId, $name]) === 0) {
                return $name;
            }
        }

        return null;
    }

    /** อีเมลที่ใช้ได้ (คอลัมน์ email ห้ามซ้ำทั้งระบบ) — คืน null ถ้ารูปแบบผิดหรือมีบัญชีอื่นใช้อยู่ */
    private function freeEmail(string $email, ?int $ownerId): ?string
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            return null;
        }

        return $this->db->int(
            'SELECT COUNT(*) FROM {users} WHERE LOWER(email) = LOWER(?) AND id <> ?',
            [$email, $ownerId ?? 0]
        ) > 0 ? null : $email;
    }

    /** สถานศึกษาของบัญชีใหม่ = วิทยาลัยที่ตั้งชื่อไว้ในหน้าตั้งค่าระบบ (เหมือนการโอนจาก RMS) */
    private function institutionId(): int
    {
        $name = (string) ($this->settings->get('college_name') ?: $this->config->get('app.college', 'วิทยาลัยเทคนิคร้อยเอ็ด'));

        return $this->institutions->findOrCreateByName($name);
    }

    /** @param array<string,mixed> $data */
    private function remember(array $data): void
    {
        try {
            $this->settings->set(self::LAST, $data, 'json', 'auth');
        } catch (Throwable) {
            // บันทึกสรุปไม่ได้ไม่ควรทำให้รอบที่สำเร็จกลายเป็นล้มเหลว
        }
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '') : '';
    }
}
