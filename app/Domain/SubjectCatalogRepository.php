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

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        try {
            return $this->db->first('SELECT * FROM {subject_catalog} WHERE id = ?', [$id]);
        } catch (PDOException) {
            return null;
        }
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
        $sql = 'INSERT INTO {subject_catalog}
                    (code, curriculum_year, name_th, name_en, theory_hours, practice_hours, credits, self_study_hours,
                     subject_group, description, objectives, competencies, source_id, imported_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en),
                    theory_hours = VALUES(theory_hours), practice_hours = VALUES(practice_hours), credits = VALUES(credits),
                    self_study_hours = VALUES(self_study_hours), subject_group = VALUES(subject_group),
                    description = VALUES(description), objectives = VALUES(objectives), competencies = VALUES(competencies),
                    source_id = VALUES(source_id), imported_at = NOW()';
        $count = 0;
        foreach (array_chunk($items, 300) as $chunk) {
            $this->db->transaction(function () use ($chunk, $sql, &$count): void {
                foreach ($chunk as $it) {
                    $row = self::row(is_array($it) ? $it : []);
                    if ($row === null) {
                        continue;
                    }
                    $this->db->run($sql, array_values($row));
                    $count++;
                }
            });
        }

        return $count;
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
        // ท-ป-น จากรายละเอียด ถ้าไม่มีใช้ค่าในตารางรายการ เช่น "1-4-3"
        $tpn = array_map('floatval', explode('-', (string) ($it['tpn'] ?? '')) + [0, 0, 0]);
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
