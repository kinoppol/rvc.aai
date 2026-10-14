<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiUnavailableException;
use App\AI\Drafter;
use App\Auth\Auth;
use App\Domain\AssignmentRepository;
use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * ใบงานของหน่วยการเรียน และงานที่มอบหมายเพิ่มเติม — ครูเขียนเองหรือให้ผู้ช่วย AI ร่างให้แล้วแก้ต่อ
 *
 * สองแบบใช้ตาราง assignments เดียวกัน แยกด้วย kind:
 *   ใบงาน (worksheet)  /courses/{courseId}/units/{unitId}/assignments/…  ผูกกับหน่วยเสมอ
 *   งาน (task)         /courses/{courseId}/tasks/…                       เลือกหน่วยหรือไม่ผูกก็ได้
 * ตรวจสิทธิ์ความเป็นเจ้าของรายวิชาทุกครั้ง โดยไม่ผูกกับสวิตช์ AI ของรายวิชา
 * เพราะการเขียนใบงานเองต้องทำได้แม้ผู้ดูแลปิดฟังก์ชัน AI ไว้
 */
final class AssignmentController
{
    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly AssignmentRepository $assignments,
        private readonly Drafter $drafter,
        private readonly Auth $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** ฟอร์มเพิ่มใบงานใหม่ หรือแก้ใบงานเดิม */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $assignment = $this->current($request, $args);
        [$course, $unit] = $this->context($request, $args, $assignment);

        $draft = $_SESSION['assignment_draft'] ?? null;
        unset($_SESSION['assignment_draft']);
        $isTask = $this->isTask($args);

        return $this->view->render($response, 'courses/assignment-edit', [
            'page' => 'courses',
            'ai' => $this->drafter->picker((int) $request->getAttribute('user')['id']),
            'course' => $course,
            'unit' => $unit,
            'assignment' => $assignment,
            'isTask' => $isTask,
            'noun' => $isTask ? 'งานที่มอบหมาย' : 'ใบงาน',
            'formUrl' => $this->formBase($course, $unit, $isTask),
            'backUrl' => Url::to($this->backPath($course, $unit, $isTask)),
            'units' => $isTask ? $this->lessonUnits((int) $course['id']) : [],
            'form' => $this->formValues($assignment, is_array($draft) ? $draft : null),
            'aiEnabled' => (int) ($course['ai_lesson_plan_enabled'] ?? 1) === 1,
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $assignment = $this->current($request, $args);
        [$course, $unit] = $this->context($request, $args, $assignment);
        $data = (array) $request->getParsedBody();
        $isTask = $this->isTask($args);
        $noun = $isTask ? 'งาน' : 'ใบงาน';

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->backToList($response, $course, $unit, $isTask);
        }

        // งานที่มอบหมาย: ครูเลือกหน่วยที่เกี่ยวข้องได้ (หรือไม่ผูกหน่วย)
        if ($isTask) {
            $unit = $this->postedUnit($course, $data['unit_id'] ?? null);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $dueAt = trim((string) ($data['due_at'] ?? ''));

        if ($title === '') {
            Flash::error('กรุณากรอกชื่อ' . $noun);
            $_SESSION['assignment_draft'] = $data;

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
        }

        $fields = [
            'title' => mb_substr($title, 0, 191),
            'description' => $description ?: null,
            'due_at' => $dueAt !== '' ? str_replace('T', ' ', $dueAt) . ':00' : null,
            'max_score' => max(0, min(999.99, (float) ($data['max_score'] ?? 10))),
            'allow_late' => isset($data['allow_late']) ? 1 : 0,
        ];
        if ($isTask) {
            $fields['unit_id'] = $unit === null ? null : (int) $unit['id'];
        }

        if ($assignment !== null) {
            $this->assignments->update((int) $assignment['id'], $fields);
            Flash::success('บันทึก' . $noun . ' ' . $title . ' แล้ว');
        } else {
            $this->assignments->create($fields + [
                'course_id' => (int) $course['id'],
                'unit_id' => $unit === null ? null : (int) $unit['id'],
                'kind' => $isTask ? 'task' : 'worksheet',
                'created_by' => (int) $user['id'],
            ]);
            Flash::success('เพิ่ม' . $noun . ' ' . $title . ' แล้ว');
        }

        return $this->backToList($response, $course, $unit, $isTask);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $assignment = $this->current($request, $args);
        [$course, $unit] = $this->context($request, $args, $assignment);
        $data = (array) $request->getParsedBody();
        $isTask = $this->isTask($args);
        $noun = $isTask ? 'งาน' : 'ใบงาน';

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->backToList($response, $course, $unit, $isTask);
        }

        if ($assignment === null || !$this->assignments->delete((int) $assignment['id'])) {
            Flash::error('ลบไม่ได้เพราะมีนักเรียนส่ง' . $noun . 'นี้แล้ว');

            return $this->backToList($response, $course, $unit, $isTask);
        }

        Flash::success('ลบ' . $noun . ' ' . $assignment['title'] . ' แล้ว');

        return $this->backToList($response, $course, $unit, $isTask);
    }

    /** ให้ผู้ช่วยร่างใบงานจากสาระสำคัญของหน่วย แล้วเติมลงฟอร์มให้ครูตรวจแก้ก่อนบันทึก */
    public function draft(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $assignment = $this->current($request, $args);
        [$course, $unit] = $this->context($request, $args, $assignment);
        $data = (array) $request->getParsedBody();
        $isTask = $this->isTask($args);

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
        }
        if ($isTask) {
            $unit = $this->postedUnit($course, $data['unit_id'] ?? null);
        }

        if ((int) ($course['ai_lesson_plan_enabled'] ?? 1) !== 1) {
            throw new HttpForbiddenException($request, 'ผู้ดูแลระบบปิดฟังก์ชัน AI ของรายวิชานี้ไว้');
        }

        $maxScore = max(1, min(999, (float) ($data['max_score'] ?? 10)));
        $system = 'คุณเป็นผู้ช่วยครูอาชีวศึกษา ออกแบบ' . ($isTask ? 'งานมอบหมาย' : 'ใบงาน')
            . 'ภาษาไทยที่สั่งงานเป็นรูปธรรม ตอบกลับเป็น JSON อ็อบเจกต์เดียว';

        try {
            $objects = $this->drafter->objects((int) $user['id'], $system, [
                'task' => 'assignment',
                'course_code' => $course['code'],
                'course_name' => $course['name'],
                // งานที่ไม่ผูกหน่วย: ใช้คำอธิบายรายวิชาเป็นบริบทแทน
                'unit' => $unit['title'] ?? 'ทั้งรายวิชา',
                'key_content' => $unit['key_content'] ?? ($course['description'] ?? ''),
                'max_score' => $maxScore,
                'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 2000),
            ], 3, (string) ($data['ai'] ?? ''));
            $draft = $this->firstWithTitle($objects);
        } catch (AiUnavailableException $e) {
            Flash::error($e->getMessage());

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
        } catch (Throwable $e) {
            $this->logger->error('ร่างใบงานไม่สำเร็จ: ' . $e->getMessage(), [
                'course_id' => (int) $course['id'],
                'unit_id' => $unit === null ? null : (int) $unit['id'],
            ]);
            Flash::error('ผู้ช่วยร่างงานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
        }

        if ($draft === null) {
            Flash::error('ผู้ช่วยไม่ได้ร่างงานออกมา กรุณาลองใหม่อีกครั้ง');

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
        }

        $_SESSION['assignment_draft'] = [
            'title' => mb_substr(trim((string) ($draft['title'] ?? '')), 0, 191),
            'description' => $this->describe($draft),
            'max_score' => $maxScore,
            'due_at' => (string) ($data['due_at'] ?? ''),
            'allow_late' => isset($data['allow_late']) ? '1' : '',
            'unit_id' => $unit === null ? '' : (string) $unit['id'],
        ];
        $this->auth->log('ai.assignment.draft', $unit === null ? 'course#' . $course['id'] : 'unit#' . $unit['id']);
        Flash::success('ผู้ช่วยร่าง' . ($isTask ? 'งาน' : 'ใบงาน') . 'ให้แล้ว · ตรวจแก้ได้ตามต้องการ แล้วกดบันทึก');

        return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
    }

    /** รวมส่วนต่าง ๆ ที่ผู้ช่วยร่างมาให้เป็นข้อความใบงานที่ครูอ่านและแก้ต่อได้ */
    private function describe(array $draft): string
    {
        $lines = [];

        $objective = Drafter::text($draft['objective'] ?? '');
        if ($objective !== '') {
            $lines[] = 'จุดประสงค์';
            $lines[] = $objective;
        }

        $steps = array_filter(array_map('strval', (array) ($draft['steps'] ?? [])));
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = 'ขั้นตอนการทำงาน';
            foreach (array_values($steps) as $i => $step) {
                $lines[] = ($i + 1) . '. ' . trim($step);
            }
        }

        $deliverable = Drafter::text($draft['deliverable'] ?? '');
        if ($deliverable !== '') {
            $lines[] = '';
            $lines[] = 'สิ่งที่ต้องส่ง';
            $lines[] = $deliverable;
        }

        $criteria = (array) ($draft['criteria'] ?? []);
        if ($criteria !== []) {
            $lines[] = '';
            $lines[] = 'เกณฑ์การให้คะแนน';
            foreach ($criteria as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $item = trim((string) ($c['item'] ?? ''));
                if ($item === '') {
                    continue;
                }
                $lines[] = '- ' . $item . ' (' . (float) ($c['score'] ?? 0) . ' คะแนน)';
            }
        }

        return mb_substr(implode("\n", $lines), 0, 20000);
    }

    /**
     * @param list<array<string,mixed>> $objects
     * @return array<string,mixed>|null
     */
    private function firstWithTitle(array $objects): ?array
    {
        foreach ($objects as $obj) {
            if (trim((string) ($obj['title'] ?? '')) !== '') {
                return $obj;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $assignment
     * @param array<string,mixed>|null $draft
     * @return array<string,mixed>
     */
    private function formValues(?array $assignment, ?array $draft): array
    {
        $base = [
            'title' => (string) ($assignment['title'] ?? ''),
            'description' => (string) ($assignment['description'] ?? ''),
            'max_score' => (float) ($assignment['max_score'] ?? 10),
            'due_at' => isset($assignment['due_at']) && $assignment['due_at']
                ? substr(str_replace(' ', 'T', (string) $assignment['due_at']), 0, 16)
                : '',
            'allow_late' => $assignment === null ? true : (bool) $assignment['allow_late'],
            'unit_id' => (string) ($assignment['unit_id'] ?? ''),
        ];

        if ($draft === null) {
            return $base;
        }

        return [
            'title' => (string) ($draft['title'] ?? $base['title']),
            'description' => (string) ($draft['description'] ?? $base['description']),
            'max_score' => (float) ($draft['max_score'] ?? $base['max_score']),
            'due_at' => (string) ($draft['due_at'] ?? $base['due_at']),
            'allow_late' => (string) ($draft['allow_late'] ?? '') !== '',
            'unit_id' => (string) ($draft['unit_id'] ?? $base['unit_id']),
        ];
    }

    /** เส้นทาง /tasks = งานที่มอบหมาย · เส้นทางใต้หน่วย = ใบงาน */
    private function isTask(array $args): bool
    {
        return !isset($args['unitId']);
    }

    /**
     * งานที่กำลังแก้ (ถ้ามี id) — ต้องอยู่ในรายวิชานี้และเป็นประเภทเดียวกับเส้นทาง
     *
     * @return array<string,mixed>|null
     */
    private function current(Request $request, array $args): ?array
    {
        if (!isset($args['id'])) {
            return null;
        }

        $assignment = $this->assignments->find((int) $args['id'], (int) $args['courseId']);
        $kind = $this->isTask($args) ? 'task' : 'worksheet';
        if ($assignment === null || ($assignment['kind'] ?? 'worksheet') !== $kind) {
            throw new HttpNotFoundException($request, $kind === 'task' ? 'ไม่พบงานนี้' : 'ไม่พบใบงานนี้');
        }

        return $assignment;
    }

    /**
     * รายวิชาต้องเป็นของครูคนนี้ · ใบงาน: หน่วยต้องอยู่ในรายวิชานั้นจริง
     * งานที่มอบหมาย: หน่วยคือหน่วยที่ผูกไว้ (หรือ ?unit_id= ตอนเพิ่มใหม่) ไม่มีก็ได้
     *
     * @param array<string,mixed>|null $assignment
     * @return array{0:array<string,mixed>,1:array<string,mixed>|null}
     */
    private function context(Request $request, array $args, ?array $assignment = null): array
    {
        $user = $request->getAttribute('user');
        $course = $this->courses->find((int) $args['courseId']);

        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== (int) $user['id']) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }

        if ($this->isTask($args)) {
            $unitId = $assignment['unit_id'] ?? ($request->getQueryParams()['unit_id'] ?? null);

            return [$course, $this->postedUnit($course, $unitId)];
        }

        $unit = $this->units->find((int) $args['unitId']);
        if ($unit === null || (int) $unit['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
        }

        return [$course, $unit];
    }

    /** @return array<string,mixed>|null หน่วยที่เลือกมา ถ้าอยู่ในรายวิชานี้จริง */
    private function postedUnit(array $course, mixed $unitId): ?array
    {
        if ($unitId === null || $unitId === '' || (int) $unitId <= 0) {
            return null;
        }
        $unit = $this->units->find((int) $unitId);

        return $unit !== null && (int) $unit['course_id'] === (int) $course['id'] ? $unit : null;
    }

    /** @return list<array<string,mixed>> หน่วยการเรียน (ไม่รวมหน่วยสอบ) ให้เลือกผูกกับงาน */
    private function lessonUnits(int $courseId): array
    {
        return array_values(array_filter(
            $this->units->forCourse($courseId),
            static fn (array $u): bool => !UnitRepository::isExam($u)
        ));
    }

    private function formBase(array $course, ?array $unit, bool $isTask): string
    {
        return Url::to($isTask
            ? '/courses/' . $course['id'] . '/tasks'
            : '/courses/' . $course['id'] . '/units/' . $unit['id'] . '/assignments');
    }

    private function backPath(array $course, ?array $unit, bool $isTask): string
    {
        return $isTask
            ? '/courses/' . $course['id'] . '?tab=tasks'
            : '/courses/' . $course['id'] . '/units/' . $unit['id'] . '/edit';
    }

    private function backToList(Response $response, array $course, ?array $unit, bool $isTask): Response
    {
        return $response->withHeader('Location', Url::to($this->backPath($course, $unit, $isTask)))->withStatus(302);
    }

    private function backToForm(Response $response, array $course, ?array $unit, mixed $id, bool $isTask = false): Response
    {
        $path = ($isTask
                ? '/courses/' . $course['id'] . '/tasks/'
                : '/courses/' . $course['id'] . '/units/' . $unit['id'] . '/assignments/')
            . ($id === null ? 'new' : $id . '/edit');

        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
