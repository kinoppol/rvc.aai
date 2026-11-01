<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use PDOException;

/**
 * สถิติการใช้ผู้ช่วย AI สำหรับผู้ดูแล (แท็บ "สถิติการใช้งาน" ใน /admin/ai)
 * อ่านจาก ai_usage_logs หนึ่งแถวต่อการเรียก AI หนึ่งครั้ง (ทั้ง AI ส่วนกลางและคีย์ของครูเอง)
 * ประเภทงานมาจากคอลัมน์ task (migration 000131) · แถวเก่าที่ไม่มี task เดาจาก ai_jobs.kind ได้เฉพาะออกข้อสอบ/แผนการสอน
 */
final class AiStatsRepository
{
    public const PERIODS = [7 => '7 วัน', 30 => '30 วัน', 90 => '3 เดือน', 180 => '6 เดือน', 365 => '1 ปี'];

    public const TASKS = [
        'chat' => 'แชทผู้ช่วย',
        'quiz' => 'ออกข้อสอบ',
        'plan' => 'แผนการสอน',
        'unit_outline' => 'ออกแบบหน่วยการเรียน',
        'unit_content' => 'ร่างเนื้อหาหน่วย',
        'assignment' => 'ร่างใบงาน',
        'course_outcomes' => 'สรุปผลลัพธ์รายวิชา',
        'ocr' => 'อ่านไฟล์สแกน',
        'model_roles' => 'จัดบทบาทโมเดล',
        '' => 'ไม่ระบุ',
    ];

    private ?bool $hasTask = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{total:int,ok:int,college:int,byok:int,users:int} */
    public function summary(string $from, ?string $to = null): array
    {
        $row = $this->db->first(
            "SELECT COUNT(*) AS total, COALESCE(SUM(succeeded), 0) AS ok,
                    COALESCE(SUM(source = 'college'), 0) AS college, COALESCE(SUM(source = 'byok'), 0) AS byok,
                    COUNT(DISTINCT user_id) AS users
             FROM {ai_usage_logs} WHERE created_at >= ? AND created_at < ?",
            [$from, $to ?? date('Y-m-d H:i:s', time() + 60)]
        ) ?? [];

        return array_map('intval', $row + ['total' => 0, 'ok' => 0, 'college' => 0, 'byok' => 0, 'users' => 0]);
    }

    /**
     * จำนวนครั้งต่อวัน (ช่วงไม่เกิน 31 วัน) หรือต่อสัปดาห์ (ช่วงยาวกว่านั้น) แยกส่วนกลาง/คีย์ของครู
     * @return list<array{label:string,tip:string,college:int,byok:int,total:int}>
     */
    public function trend(int $days): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = $this->db->all(
            'SELECT DATE(created_at) AS d, source, COUNT(*) AS n FROM {ai_usage_logs}
             WHERE created_at >= ? GROUP BY DATE(created_at), source',
            [$from]
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['d']][$r['source']] = (int) $r['n'];
        }

        $weekly = $days > 31;
        $buckets = [];
        for ($t = strtotime($from); $t <= time(); $t += 86400) {
            $d = date('Y-m-d', $t);
            // รายสัปดาห์: รวมเข้าวันจันทร์ของสัปดาห์นั้น
            $key = $weekly ? date('Y-m-d', strtotime('monday this week', $t)) : $d;
            $buckets[$key] ??= ['college' => 0, 'byok' => 0];
            $buckets[$key]['college'] += $byDay[$d]['college'] ?? 0;
            $buckets[$key]['byok'] += $byDay[$d]['byok'] ?? 0;
        }

        $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $out = [];
        foreach ($buckets as $key => $b) {
            $t = strtotime($key);
            $label = (int) date('j', $t) . ' ' . $months[(int) date('n', $t)];
            $out[] = [
                'label' => $label,
                'tip' => ($weekly ? 'สัปดาห์ของ ' : '') . $label . ' ' . ((int) date('Y', $t) + 543),
                'college' => $b['college'],
                'byok' => $b['byok'],
                'total' => $b['college'] + $b['byok'],
            ];
        }

        return $out;
    }

    /** @return list<array{task:string,label:string,n:int,ok:int}> */
    public function byTask(string $from): array
    {
        $rows = $this->db->all(
            'SELECT ' . $this->taskExpr() . " AS task, COUNT(*) AS n, COALESCE(SUM(l.succeeded), 0) AS ok
             FROM {ai_usage_logs} l LEFT JOIN {ai_jobs} j ON j.id = l.job_id
             WHERE l.created_at >= ? GROUP BY task ORDER BY n DESC",
            [$from]
        );

        return array_map(static fn (array $r): array => [
            'task' => (string) $r['task'],
            'label' => self::TASKS[(string) $r['task']] ?? (string) $r['task'],
            'n' => (int) $r['n'],
            'ok' => (int) $r['ok'],
        ], $rows);
    }

    /** @return list<array{model:string,source:string,n:int,ok:int}> */
    public function byModel(string $from, int $limit = 10): array
    {
        return array_map(static fn (array $r): array => [
            'model' => (string) $r['model'] !== '' ? (string) $r['model'] : '(ไม่ระบุชื่อโมเดล)',
            'source' => (string) $r['source'],
            'n' => (int) $r['n'],
            'ok' => (int) $r['ok'],
        ], $this->db->all(
            'SELECT model, source, COUNT(*) AS n, COALESCE(SUM(succeeded), 0) AS ok FROM {ai_usage_logs}
             WHERE created_at >= ? GROUP BY model, source ORDER BY n DESC LIMIT ' . max(1, $limit),
            [$from]
        ));
    }

    /**
     * ตารางวัน × ชั่วโมง — แถว 0 = จันทร์ … 6 = อาทิตย์ · ค่า = จำนวนครั้ง
     * @return array{cells:list<list<int>>,max:int}
     */
    public function heat(string $from): array
    {
        $cells = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;
        // WEEKDAY(): 0 = จันทร์
        foreach ($this->db->all(
            'SELECT WEEKDAY(created_at) AS wd, HOUR(created_at) AS h, COUNT(*) AS n FROM {ai_usage_logs}
             WHERE created_at >= ? GROUP BY wd, h',
            [$from]
        ) as $r) {
            $cells[(int) $r['wd']][(int) $r['h']] = (int) $r['n'];
            $max = max($max, (int) $r['n']);
        }

        return ['cells' => $cells, 'max' => $max];
    }

    /**
     * ครูทุกคน (ครู + ผู้ดูแลครู และผู้ใช้อื่นที่เคยใช้ AI) พร้อมจำนวนครั้งในช่วงนี้เทียบกับช่วงก่อนหน้าที่ยาวเท่ากัน
     * @return list<array<string,mixed>>
     */
    public function teachers(string $from, string $prevFrom): array
    {
        $rows = $this->db->all(
            "SELECT u.id, u.username, u.full_name, u.subject_area, u.role,
                    COALESCE(SUM(l.created_at >= ?), 0) AS n,
                    COALESCE(SUM(l.created_at >= ? AND l.created_at < ?), 0) AS prev,
                    COALESCE(SUM(l.created_at >= ? AND l.source = 'college'), 0) AS college,
                    COALESCE(SUM(l.created_at >= ? AND l.source = 'byok'), 0) AS byok,
                    COALESCE(SUM(l.created_at >= ? AND l.succeeded = 1), 0) AS ok,
                    COUNT(l.id) AS ever, MAX(l.created_at) AS last_at
             FROM {users} u
             LEFT JOIN {ai_usage_logs} l ON l.user_id = u.id
             WHERE u.status = 'active' AND u.role <> 'student'
             GROUP BY u.id, u.username, u.full_name, u.subject_area, u.role
             HAVING u.role IN ('teacher', 'supervisor') OR ever > 0
             ORDER BY n DESC, ever DESC, u.full_name",
            [$from, $prevFrom, $from, $from, $from, $from]
        );

        $top = [];
        foreach ($this->db->all(
            'SELECT l.user_id, ' . $this->taskExpr() . ' AS task, COUNT(*) AS n
             FROM {ai_usage_logs} l LEFT JOIN {ai_jobs} j ON j.id = l.job_id
             WHERE l.created_at >= ? GROUP BY l.user_id, task',
            [$from]
        ) as $r) {
            $uid = (int) $r['user_id'];
            if (!isset($top[$uid]) || (int) $r['n'] > $top[$uid]['n']) {
                $top[$uid] = ['task' => (string) $r['task'], 'n' => (int) $r['n']];
            }
        }

        $keys = array_flip(array_map('intval', array_column(
            $this->db->all("SELECT DISTINCT user_id FROM {user_api_keys} WHERE status = 'active'"),
            'user_id'
        )));

        return array_map(static function (array $r) use ($top, $keys): array {
            $id = (int) $r['id'];
            $n = (int) $r['n'];
            $prev = (int) $r['prev'];

            return [
                'id' => $id,
                'name' => (string) ($r['full_name'] ?: $r['username']),
                'username' => (string) $r['username'],
                'subject' => (string) ($r['subject_area'] ?? ''),
                'role' => (string) $r['role'],
                'n' => $n,
                'prev' => $prev,
                // แนวโน้มเทียบช่วงก่อนหน้า: null = ไม่มีข้อมูลให้เทียบ
                'change' => $prev > 0 ? (int) round(($n - $prev) / $prev * 100) : null,
                'college' => (int) $r['college'],
                'byok' => (int) $r['byok'],
                'ok' => (int) $r['ok'],
                'ever' => (int) $r['ever'],
                'last_at' => $r['last_at'],
                'top_task' => isset($top[$id]) ? (self::TASKS[$top[$id]['task']] ?? $top[$id]['task']) : null,
                'has_key' => isset($keys[$id]),
            ];
        }, $rows);
    }

    /** ประเภทงานของแต่ละแถว (ต้อง JOIN ai_jobs เป็น j) */
    private function taskExpr(): string
    {
        $fromJob = "CASE j.kind WHEN 'quiz' THEN 'quiz' WHEN 'lesson_plan' THEN 'plan' ELSE '' END";

        return $this->hasTaskColumn() ? "COALESCE(NULLIF(l.task, ''), $fromJob)" : $fromJob;
    }

    private function hasTaskColumn(): bool
    {
        if ($this->hasTask === null) {
            try {
                $this->db->all('SELECT task FROM {ai_usage_logs} LIMIT 0');
                $this->hasTask = true;
            } catch (PDOException) {
                $this->hasTask = false;
            }
        }

        return $this->hasTask;
    }
}
