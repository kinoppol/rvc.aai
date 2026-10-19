<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Roles;
use App\Domain\CourseRepository;
use App\Domain\PlanSignerRepository;
use App\Support\Db;
use App\Support\View;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

/**
 * ตรวจการจัดทำแผนการสอน (อ่านอย่างเดียว) — สำหรับหัวหน้างานพัฒนาหลักสูตร ผู้ดูแลครู และผู้ดูแลระบบ
 * ดูความครบถ้วนของแผนทุกรายวิชาในภาคเรียน และเปิดแผนที่สั่งพิมพ์ของรายวิชาใดก็ได้
 */
final class PlanReviewController
{
    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly PlanSignerRepository $signers,
        private readonly Db $db,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (!Roles::canReviewPlans($request->getAttribute('user'))) {
            throw new HttpForbiddenException($request, 'หน้านี้สำหรับหัวหน้างานพัฒนาหลักสูตรและผู้ดูแล');
        }

        $params = $request->getQueryParams();
        $terms = $this->courses->terms();
        $termParam = (string) ($params['term'] ?? '');
        $termId = $termParam === 'all' ? null : ((int) $termParam ?: $this->courses->currentTermId());
        if ($termId !== null && !in_array($termId, array_map('intval', array_column($terms, 'id')), true)) {
            $termId = $this->courses->currentTermId();
        }
        $query = trim((string) ($params['q'] ?? ''));

        $rows = $this->overview($termId, $query);
        $complete = count(array_filter($rows, static fn (array $r): bool => $r['missing'] === []));

        return $this->view->render($response, 'plans/review', [
            'page' => 'plan-review',
            'rows' => $rows,
            'terms' => $terms,
            'termId' => $termId,
            'query' => $query,
            'complete' => $complete,
            'reportedUnits' => array_sum(array_column($rows, 'reported')),
            'lessonUnits' => array_sum(array_column($rows, 'lesson_units')),
            'withDeptHead' => $this->signers->ready(),
        ]);
    }

    /**
     * รายวิชาในภาคเรียน (null = ทุกภาค) พร้อมความครบของข้อมูลแผนการสอน
     * ตาราง unit_reports / คอลัมน์ dept_head_id อาจยังไม่มีถ้ายังไม่รันไมเกรชัน — ตัดคอลัมน์นั้นออกไปก่อน
     *
     * @return list<array<string,mixed>>
     */
    private function overview(?int $termId, string $query): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($termId !== null) {
            $where[] = 'c.term_id = ?';
            $params[] = $termId;
        }
        if ($query !== '') {
            $where[] = '(c.code LIKE ? OR c.name LIKE ? OR t.full_name LIKE ?)';
            array_push($params, "%$query%", "%$query%", "%$query%");
        }

        $deptHead = $this->signers->ready();
        $sql = static fn (bool $reports): string => sprintf(
            "SELECT c.id, c.code, c.name, c.status, c.plan_date, c.learning_outcomes, c.description, %s
                    t.full_name AS teacher_name, cr.name AS classroom_name,
                    (SELECT COUNT(*) FROM {units} x WHERE x.course_id = c.id AND x.kind = 'lesson') AS lesson_units,
                    %s AS reported
             FROM {courses} c
             JOIN {users} t ON t.id = c.teacher_id
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             LEFT JOIN {users} dh ON dh.id = %s
             WHERE %s
             ORDER BY c.status = 'archived', t.full_name, c.code",
            $deptHead ? 'dh.full_name AS dept_head_name,' : 'NULL AS dept_head_name,',
            $reports
                ? "(SELECT COUNT(*) FROM {unit_reports} r JOIN {units} x ON x.id = r.unit_id WHERE x.course_id = c.id AND x.kind = 'lesson')"
                : '0',
            $deptHead ? 'c.dept_head_id' : 'NULL',
            implode(' AND ', $where)
        );

        try {
            $rows = $this->db->all($sql(true), $params);
        } catch (PDOException) {
            $rows = $this->db->all($sql(false), $params);
        }

        foreach ($rows as $i => $r) {
            $missing = [];
            if (trim((string) $r['description']) === '') {
                $missing[] = 'คำอธิบายรายวิชา';
            }
            if (trim((string) $r['learning_outcomes']) === '') {
                $missing[] = 'ผลลัพธ์การเรียนรู้';
            }
            if ((int) $r['lesson_units'] === 0) {
                $missing[] = 'หน่วยการเรียน';
            }
            if ($r['plan_date'] === null) {
                $missing[] = 'วันที่จัดทำแผน';
            }
            if ($deptHead && $r['dept_head_name'] === null) {
                $missing[] = 'หัวหน้าแผนก';
            }
            $rows[$i]['missing'] = $missing;
            $rows[$i]['lesson_units'] = (int) $r['lesson_units'];
            $rows[$i]['reported'] = (int) $r['reported'];
        }

        return $rows;
    }
}
