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
 * ใบงานของหน่วยการเรียน — ครูเขียนเองหรือให้ผู้ช่วย AI ร่างให้แล้วแก้ต่อ
 *
 * ใบงานผูกกับหน่วยการเรียน (unit_id) และรายวิชา (course_id) เสมอ
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
        [$course, $unit] = $this->context($request, $args);
        $assignment = isset($args['id'])
            ? $this->assignments->find((int) $args['id'], (int) $course['id'])
            : null;

        if (isset($args['id']) && $assignment === null) {
            throw new HttpNotFoundException($request, 'ไม่พบใบงานนี้');
        }

        $draft = $_SESSION['assignment_draft'] ?? null;
        unset($_SESSION['assignment_draft']);

        return $this->view->render($response, 'courses/assignment-edit', [
            'page' => 'courses',
            'course' => $course,
            'unit' => $unit,
            'assignment' => $assignment,
            'form' => $this->formValues($assignment, is_array($draft) ? $draft : null),
            'aiEnabled' => (int) ($course['ai_lesson_plan_enabled'] ?? 1) === 1,
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $unit] = $this->context($request, $args);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->backToUnit($response, $course, $unit);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $dueAt = trim((string) ($data['due_at'] ?? ''));

        if ($title === '') {
            Flash::error('กรุณากรอกชื่อใบงาน');
            $_SESSION['assignment_draft'] = $data;

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null);
        }

        $fields = [
            'title' => mb_substr($title, 0, 191),
            'description' => $description ?: null,
            'due_at' => $dueAt !== '' ? str_replace('T', ' ', $dueAt) . ':00' : null,
            'max_score' => max(0, min(999.99, (float) ($data['max_score'] ?? 10))),
            'allow_late' => isset($data['allow_late']) ? 1 : 0,
        ];

        if (isset($args['id'])) {
            $assignment = $this->assignments->find((int) $args['id'], (int) $course['id']);
            if ($assignment === null) {
                throw new HttpNotFoundException($request, 'ไม่พบใบงานนี้');
            }

            $this->assignments->update((int) $assignment['id'], $fields);
            Flash::success('บันทึกใบงาน ' . $title . ' แล้ว');
        } else {
            $this->assignments->create($fields + [
                'course_id' => (int) $course['id'],
                'unit_id' => (int) $unit['id'],
                'created_by' => (int) $user['id'],
            ]);
            Flash::success('เพิ่มใบงาน ' . $title . ' แล้ว');
        }

        return $this->backToUnit($response, $course, $unit);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        [$course, $unit] = $this->context($request, $args);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->backToUnit($response, $course, $unit);
        }

        $assignment = $this->assignments->find((int) $args['id'], (int) $course['id']);
        if ($assignment === null) {
            throw new HttpNotFoundException($request, 'ไม่พบใบงานนี้');
        }

        if (!$this->assignments->delete((int) $assignment['id'])) {
            Flash::error('ลบไม่ได้เพราะมีนักเรียนส่งงานของใบงานนี้แล้ว');

            return $this->backToUnit($response, $course, $unit);
        }

        Flash::success('ลบใบงาน ' . $assignment['title'] . ' แล้ว');

        return $this->backToUnit($response, $course, $unit);
    }

    /** ให้ผู้ช่วยร่างใบงานจากสาระสำคัญของหน่วย แล้วเติมลงฟอร์มให้ครูตรวจแก้ก่อนบันทึก */
    public function draft(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $unit] = $this->context($request, $args);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null);
        }

        if ((int) ($course['ai_lesson_plan_enabled'] ?? 1) !== 1) {
            throw new HttpForbiddenException($request, 'ผู้ดูแลระบบปิดฟังก์ชัน AI ของรายวิชานี้ไว้');
        }

        $maxScore = max(1, min(999, (float) ($data['max_score'] ?? 10)));
        $system = 'คุณเป็นผู้ช่วยครูอาชีวศึกษา ออกแบบใบงานภาษาไทยที่สั่งงานเป็นรูปธรรม '
            . 'ตอบกลับเป็น JSON อ็อบเจกต์เดียว';

        try {
            $objects = $this->drafter->objects((int) $user['id'], $system, [
                'task' => 'assignment',
                'course_code' => $course['code'],
                'course_name' => $course['name'],
                'unit' => $unit['title'],
                'key_content' => $unit['key_content'],
                'max_score' => $maxScore,
                'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 500),
            ], 3);
            $draft = $this->firstWithTitle($objects);
        } catch (AiUnavailableException $e) {
            Flash::error($e->getMessage());

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null);
        } catch (Throwable $e) {
            $this->logger->error('ร่างใบงานไม่สำเร็จ: ' . $e->getMessage(), [
                'course_id' => (int) $course['id'],
                'unit_id' => (int) $unit['id'],
            ]);
            Flash::error('ผู้ช่วยร่างใบงานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null);
        }

        if ($draft === null) {
            Flash::error('ผู้ช่วยไม่ได้ร่างใบงานออกมา กรุณาลองใหม่อีกครั้ง');

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null);
        }

        $_SESSION['assignment_draft'] = [
            'title' => mb_substr(trim((string) ($draft['title'] ?? '')), 0, 191),
            'description' => $this->describe($draft),
            'max_score' => $maxScore,
            'due_at' => (string) ($data['due_at'] ?? ''),
            'allow_late' => isset($data['allow_late']) ? '1' : '',
        ];
        $this->auth->log('ai.assignment.draft', 'unit#' . $unit['id']);
        Flash::success('ผู้ช่วยร่างใบงานให้แล้ว · ตรวจแก้ได้ตามต้องการ แล้วกดบันทึก');

        return $this->backToForm($response, $course, $unit, $args['id'] ?? null);
    }

    /** รวมส่วนต่าง ๆ ที่ผู้ช่วยร่างมาให้เป็นข้อความใบงานที่ครูอ่านและแก้ต่อได้ */
    private function describe(array $draft): string
    {
        $lines = [];

        $objective = trim((string) ($draft['objective'] ?? ''));
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

        $deliverable = trim((string) ($draft['deliverable'] ?? ''));
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
        ];
    }

    /**
     * รายวิชาต้องเป็นของครูคนนี้ และหน่วยการเรียนต้องอยู่ในรายวิชานั้นจริง
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function context(Request $request, array $args): array
    {
        $user = $request->getAttribute('user');
        $course = $this->courses->find((int) $args['courseId']);

        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== (int) $user['id']) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }

        $unit = $this->units->find((int) $args['unitId']);
        if ($unit === null || (int) $unit['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
        }

        return [$course, $unit];
    }

    /** @param array<string,mixed> $course */
    private function backToUnit(Response $response, array $course, array $unit): Response
    {
        return $response
            ->withHeader('Location', Url::to('/courses/' . $course['id'] . '/units/' . $unit['id'] . '/edit'))
            ->withStatus(302);
    }

    /** @param array<string,mixed> $course */
    private function backToForm(Response $response, array $course, array $unit, mixed $id): Response
    {
        $path = '/courses/' . $course['id'] . '/units/' . $unit['id'] . '/assignments/'
            . ($id === null ? 'new' : $id . '/edit');

        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
