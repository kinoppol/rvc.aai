<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use App\Support\Paths;
use PDOException;
use RuntimeException;

/**
 * คลังรายวิชามาตรฐาน สอศ. (ตาราง subject_catalog, migration 000138)
 * ข้อมูลมาจากระบบ ศธ.02 ออนไลน์ (std2018.vec.go.th) เก็บไว้ในโปรเจกต์เป็น database/data/vec-subjects-*.json.gz
 * นำเข้าอัตโนมัติครั้งแรกที่มีคนค้นรหัสวิชา (ตารางว่าง) หรือกดนำเข้าใหม่จากหน้า /admin/rms
 */
final class SubjectCatalogRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** มีตารางแล้วหรือยัง (ก่อนรัน migration 000138 = ไม่มี ฟีเจอร์นี้ซ่อนตัว) */
    public function ready(): bool
    {
        try {
            $this->db->value('SELECT 1 FROM {subject_catalog} LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function count(): int
    {
        try {
            return $this->db->int('SELECT COUNT(*) FROM {subject_catalog}');
        } catch (PDOException) {
            return 0;
        }
    }

    /** @return array{count:int, years:array<int,int>, imported_at:?string} */
    public function stats(): array
    {
        try {
            $years = [];
            foreach ($this->db->all('SELECT curriculum_year, COUNT(*) AS n FROM {subject_catalog} GROUP BY curriculum_year ORDER BY curriculum_year DESC') as $r) {
                $years[(int) $r['curriculum_year']] = (int) $r['n'];
            }

            return [
                'count' => array_sum($years),
                'years' => $years,
                'imported_at' => $this->db->value('SELECT MAX(imported_at) FROM {subject_catalog}') ?: null,
            ];
        } catch (PDOException) {
            return ['count' => 0, 'years' => [], 'imported_at' => null];
        }
    }

    /**
     * รายวิชาตามรหัส (ปีหลักสูตรล่าสุดก่อน) — ตารางว่างจะนำเข้าจากไฟล์ในโปรเจกต์ให้ก่อน
     * @return list<array<string,mixed>>
     */
    public function byCode(string $code): array
    {
        $code = self::normaliseCode($code);
        if ($code === '' || !$this->ensureImported()) {
            return [];
        }

        return $this->db->all('SELECT * FROM {subject_catalog} WHERE code = ? ORDER BY curriculum_year DESC', [$code]);
    }

    /**
     * ค้นด้วยรหัสขึ้นต้น หรือชื่อวิชาบางส่วน — ใช้เป็นตัวเลือกเติมอัตโนมัติ
     * @return list<array{code:string,name_th:string,curriculum_year:int,theory_hours:int,practice_hours:int,credits:string}>
     */
    public function search(string $q, int $limit = 12): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 || !$this->ensureImported()) {
            return [];
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        return $this->db->all(
            'SELECT code, name_th, curriculum_year, theory_hours, practice_hours, credits FROM {subject_catalog}
             WHERE code LIKE ? OR name_th LIKE ?
             ORDER BY (code LIKE ?) DESC, code, curriculum_year DESC
             LIMIT ' . max(1, min(30, $limit)),
            [$like . '%', '%' . $like . '%', $like . '%']
        );
    }

    /**
     * รายการสำหรับหน้าตรวจสอบของผู้ดูแลระบบ: ค้นรหัส/ชื่อไทย/ชื่ออังกฤษ กรองปีหลักสูตรและระดับ (2 = ปวช. 3 = ปวส.)
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public function browse(string $q, int $year, string $level, int $page, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(code LIKE ? OR name_th LIKE ? OR name_en LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if ($year > 0) {
            $where[] = 'curriculum_year = ?';
            $params[] = $year;
        }
        if (in_array($level, ['2', '3'], true)) {
            $where[] = 'code LIKE ?';
            $params[] = $level . '%';
        }
        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        try {
            $total = $this->db->int('SELECT COUNT(*) FROM {subject_catalog}' . $sqlWhere, $params);
            $rows = $this->db->all(
                'SELECT id, code, curriculum_year, name_th, name_en, theory_hours, practice_hours, credits, subject_group,
                        (description IS NOT NULL) AS has_description, (objectives IS NOT NULL) AS has_objectives,
                        (competencies IS NOT NULL) AS has_competencies
                 FROM {subject_catalog}' . $sqlWhere . '
                 ORDER BY code, curriculum_year DESC
                 LIMIT ' . $perPage . ' OFFSET ' . (max(0, $page - 1) * $perPage),
                $params
            );
        } catch (PDOException) {
            return ['rows' => [], 'total' => 0];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** รายวิชาหนึ่งรายการ พร้อม learning_area (กลุ่มสาระการเรียนรู้จากรหัสวิชา) @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        try {
            $row = $this->db->first('SELECT * FROM {subject_catalog} WHERE id = ?', [$id]);
        } catch (PDOException) {
            return null;
        }
        if ($row !== null) {
            $row['learning_area'] = self::learningArea((string) $row['code']);
        }

        return $row;
    }

    /**
     * ข้อมูลหน้าค้นหา (ครูและผู้ดูแลใช้ร่วมกัน) จาก query string: q, year, level, page, show
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function listing(array $query, int $perPage = 50): array
    {
        $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 100);
        $year = (int) ($query['year'] ?? 0);
        $level = (string) ($query['level'] ?? '');
        $level = in_array($level, ['2', '3'], true) ? $level : '';
        $page = max(1, (int) ($query['page'] ?? 1));
        $ready = $this->ready();
        if ($ready) {
            $this->ensureImported();
        }
        $result = $ready ? $this->browse($q, $year, $level, $page, $perPage) : ['rows' => [], 'total' => 0];

        return [
            'ready' => $ready,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'pageNo' => $page,
            'filters' => ['q' => $q, 'year' => $year, 'level' => $level],
            // ส่วน query ของตัวกรองปัจจุบัน — ใช้ต่อท้ายลิงก์หน้า/รายละเอียด
            'qs' => http_build_query(array_filter(['q' => $q, 'year' => $year ?: null, 'level' => $level, 'page' => $page > 1 ? $page : null])),
            'qsNoPage' => http_build_query(array_filter(['q' => $q, 'year' => $year ?: null, 'level' => $level])),
            'show' => isset($query['show']) ? $this->find((int) $query['show']) : null,
        ];
    }

    /**
     * กลุ่มสาระการเรียนรู้ตามโครงสร้างรหัสวิชา หลักสูตร ปวช./ปวส. 2567
     * x0000-1Yxx = หมวดสมรรถนะแกนกลาง (Y: 1 ภาษาไทย 2 ภาษาต่างประเทศ 3 วิทยาศาสตร์ 4 คณิตศาสตร์ 5 สังคมศึกษา 6 สุขศึกษาและพลศึกษา)
     * x0000-2xxx = กิจกรรมเสริมหลักสูตร · รหัสอื่น (วิชาชีพ/เลือกเสรี) = กลุ่มสาระการเรียนรู้การงานอาชีพ ตามใบรายวิชาของ ศธ.02
     */
    public static function learningArea(string $code): string
    {
        $areas = [
            '1' => 'ภาษาไทย', '2' => 'ภาษาต่างประเทศ', '3' => 'วิทยาศาสตร์',
            '4' => 'คณิตศาสตร์', '5' => 'สังคมศึกษา', '6' => 'สุขศึกษาและพลศึกษา',
        ];
        if (preg_match('/^\d0000[-*]1(\d)/', $code, $m) === 1 && isset($areas[$m[1]])) {
            return 'กลุ่มสาระการเรียนรู้' . $areas[$m[1]];
        }
        if (preg_match('/^\d0000[-*]20/', $code) === 1) {
            return 'กิจกรรมเสริมหลักสูตร';
        }

        return 'กลุ่มสาระการเรียนรู้การงานอาชีพ';
    }

    /** จำนวนรายวิชาที่ยังขาดคำอธิบาย/จุดประสงค์/สมรรถนะ — ให้ผู้ดูแลเห็นคุณภาพข้อมูล @return array<string,int> */
    public function gaps(): array
    {
        try {
            $row = $this->db->first(
                'SELECT SUM(description IS NULL) AS description, SUM(objectives IS NULL) AS objectives,
                        SUM(competencies IS NULL) AS competencies FROM {subject_catalog}'
            ) ?? [];
        } catch (PDOException) {
            $row = [];
        }

        return array_map('intval', $row + ['description' => 0, 'objectives' => 0, 'competencies' => 0]);
    }

    /** นำเข้าครั้งแรกเมื่อตารางยังว่าง · คืน false เมื่อยังไม่มีตาราง */
    public function ensureImported(): bool
    {
        if (!$this->ready()) {
            return false;
        }
        if ($this->count() === 0) {
            try {
                $this->importBundled();
            } catch (RuntimeException) {
                // ไม่มีไฟล์ข้อมูล — ปล่อยให้ค้นแล้วไม่พบ
            }
        }

        return true;
    }

    /** ไฟล์ข้อมูลที่มากับโปรเจกต์ @return list<string> */
    public static function bundledFiles(): array
    {
        return glob(Paths::root() . '/database/data/vec-subjects-*.json.gz') ?: [];
    }

    /**
     * นำเข้า/ปรับปรุงจากไฟล์ทั้งหมดใน database/data (upsert ตามรหัส + ปีหลักสูตร)
     * @return array{rows:int, files:int}
     */
    public function importBundled(): array
    {
        $files = self::bundledFiles();
        if ($files === []) {
            throw new RuntimeException('ไม่พบไฟล์ข้อมูลรายวิชา (database/data/vec-subjects-*.json.gz)');
        }
        $rows = 0;
        foreach ($files as $file) {
            $json = @gzdecode((string) file_get_contents($file));
            $items = $json === false ? null : json_decode($json, true);
            if (!is_array($items)) {
                throw new RuntimeException('อ่านไฟล์ข้อมูลรายวิชาไม่ได้: ' . basename($file));
            }
            $rows += $this->import($items);
        }

        return ['rows' => $rows, 'files' => count($files)];
    }

    /** @param list<array<string,mixed>> $items */
    public function import(array $items): int
    {
        $variants = $this->hasVariants();
        $cols = 'code, curriculum_year, name_th, name_en, theory_hours, practice_hours, credits, self_study_hours,
                 subject_group, description, objectives, competencies, source_id' . ($variants ? ', tpn_variants' : '');
        $sql = 'INSERT INTO {subject_catalog} (' . $cols . ', imported_at)
                VALUES (' . str_repeat('?, ', $variants ? 14 : 13) . 'NOW())
                ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en),
                    theory_hours = VALUES(theory_hours), practice_hours = VALUES(practice_hours), credits = VALUES(credits),
                    self_study_hours = VALUES(self_study_hours), subject_group = VALUES(subject_group),
                    description = VALUES(description), objectives = VALUES(objectives), competencies = VALUES(competencies),
                    source_id = VALUES(source_id),' . ($variants ? ' tpn_variants = VALUES(tpn_variants),' : '') . ' imported_at = NOW()';
        $count = 0;
        foreach (array_chunk(self::merge($items), 300) as $chunk) {
            $this->db->transaction(function () use ($chunk, $sql, $variants, &$count): void {
                foreach ($chunk as $row) {
                    if (!$variants) {
                        unset($row['variants']);
                    }
                    $this->db->run($sql, array_values($row));
                    $count++;
                }
            });
        }

        return $count;
    }

    /** มีคอลัมน์ tpn_variants แล้วหรือยัง (migration 000139) */
    public function hasVariants(): bool
    {
        try {
            return $this->db->first("SHOW COLUMNS FROM {subject_catalog} LIKE 'tpn_variants'") !== null;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * ต้นทางมีรหัสเดียวกันซ้ำหลายแถว (ตามสาขาที่นำไปใช้) บางแถว ท-ป-น ต่างกัน
     * รวมเป็นแถวเดียวต่อรหัส + ปี: ใช้ ท-ป-น ที่พบบ่อยที่สุด (เท่ากันใช้แถวแรก) และเก็บแบบอื่นไว้ใน variants
     * ข้อความ (คำอธิบาย ฯลฯ) ใช้แถวที่มีข้อมูลยาวที่สุด
     * @param list<mixed> $items
     * @return list<array<string,mixed>>
     */
    private static function merge(array $items): array
    {
        $groups = [];
        foreach ($items as $it) {
            $row = self::row(is_array($it) ? $it : []);
            if ($row !== null) {
                $groups[$row['code'] . '|' . $row['year']][] = $row;
            }
        }
        $out = [];
        foreach ($groups as $rows) {
            $tally = [];
            foreach ($rows as $r) {
                $key = self::tpn($r);
                $tally[$key] = ($tally[$key] ?? 0) + 1;
            }
            arsort($tally, SORT_NUMERIC); // PHP 8 sort คงลำดับเดิมเมื่อเท่ากัน
            $main = (string) array_key_first($tally);
            $best = $rows[0];
            foreach ($rows as $r) {
                if (self::tpn($r) === $main && self::richness($r) > self::richness($best)) {
                    $best = $r;
                }
            }
            if (self::tpn($best) !== $main) {
                foreach ($rows as $r) {
                    if (self::tpn($r) === $main) {
                        $best = $r;
                        break;
                    }
                }
            }
            // ข้อความที่แถวหลักไม่มี เติมจากแถวอื่นของรหัสเดียวกัน
            foreach (['name_en', 'group', 'description', 'objectives', 'competencies'] as $f) {
                foreach ($rows as $r) {
                    if (($best[$f] ?? null) === null && $r[$f] !== null) {
                        $best[$f] = $r[$f];
                    }
                }
            }
            $others = array_slice(array_keys($tally), 1);
            $best['variants'] = $others === [] ? null : mb_substr(implode(', ', $others), 0, 191);
            $out[] = $best;
        }

        return $out;
    }

    /** @param array<string,mixed> $r */
    private static function tpn(array $r): string
    {
        return $r['theory'] . '-' . $r['practice'] . '-' . ($r['credits'] + 0);
    }

    /** @param array<string,mixed> $r */
    private static function richness(array $r): int
    {
        return mb_strlen((string) $r['description']) + mb_strlen((string) $r['objectives']) + mb_strlen((string) $r['competencies']);
    }

    /** แถวสำหรับบันทึก — null เมื่อข้อมูลไม่ครบ (ไม่มีรหัส/ปี/ชื่อ) @return array<string,mixed>|null */
    private static function row(array $it): ?array
    {
        $code = self::normaliseCode((string) ($it['code'] ?? ''));
        $year = (int) ($it['year'] ?? 0);
        $name = trim((string) ($it['name'] ?? ''));
        if ($code === '' || $year < 2500 || $name === '') {
            return null;
        }
        // ท-ป-น ใช้ค่าในตารางรายการ ("1-4-3") ก่อน: เป็นค่าของแถวนั้นจริง ส่วนหน้ารายละเอียดของ ศธ.02
        // ค้นตามรหัสจึงตอบค่าเดียวกันทุกแถวที่รหัสซ้ำ (บางทีเป็น "*-*-4") — ใช้เมื่อ tpn อ่านไม่ได้เท่านั้น
        $tpn = explode('-', (string) ($it['tpn'] ?? ''));
        if (count($tpn) === 3 && array_filter($tpn, 'is_numeric') === $tpn) {
            [$it['theory'], $it['practice'], $it['credits']] = $tpn;
        }
        $tpn = [0, 0, 0];
        $num = static fn ($v, $fallback): float => is_numeric($v) ? (float) $v : (float) $fallback;
        $text = static fn ($v): ?string => ($v = trim((string) $v)) === '' ? null : $v;

        return [
            'code' => $code,
            'year' => $year,
            'name_th' => mb_substr($name, 0, 255),
            'name_en' => $text(mb_substr((string) ($it['name_en'] ?? ''), 0, 255)),
            'theory' => max(0, min(255, (int) $num($it['theory'] ?? null, $tpn[0]))),
            'practice' => max(0, min(255, (int) $num($it['practice'] ?? null, $tpn[1]))),
            'credits' => max(0, min(99, $num($it['credits'] ?? null, $tpn[2]))),
            'self' => max(0, min(255, (int) $num($it['self'] ?? null, 0))),
            'group' => $text(mb_substr((string) ($it['group'] ?? $it['list_group'] ?? ''), 0, 191)),
            'description' => $text($it['description'] ?? ''),
            'objectives' => $text($it['objectives'] ?? ''),
            'competencies' => $text($it['competencies'] ?? ''),
            'source_id' => $text(mb_substr((string) ($it['id'] ?? ''), 0, 20)),
        ];
    }

    /**
     * "20000 1101" / "20000–1101" → "20000-1101"
     * ต้นทางมีรหัสแบบ "21701*2001" (รายวิชาที่สถานศึกษาเพิ่มเอง) — เก็บเครื่องหมาย * ไว้ตามเดิม
     */
    public static function normaliseCode(string $code): string
    {
        $code = trim(str_replace(['–', '—', '−', ' '], '-', $code), " -\t");

        return preg_match('/^[0-9A-Za-z]{2,6}(?:[-*][0-9A-Za-z]{2,6})+$/', $code) === 1 ? $code : '';
    }
}
