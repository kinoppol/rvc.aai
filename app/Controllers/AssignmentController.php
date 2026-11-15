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
use App\Support\Paths;
use App\Support\UnitPlanFields;
use App\Support\Url;
use App\Support\View;
use App\Support\WorksheetSheet;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
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
            'files' => $assignment ? $this->assignments->files((int) $assignment['id']) : [],
            'filesReady' => $this->assignments->filesReady(),
            // ข้อ 1-6, 8, 10 ของใบงานตามแบบฟอร์มแผน 2567 (เฉพาะใบงาน และเมื่อรัน migration 000141 แล้ว)
            'sheetOn' => !$isTask && $this->assignments->sheetReady(),
            'sheetBefore' => array_diff_key(WorksheetSheet::FIELDS, ['summary' => 1, 'references' => 1]),
            'sheetAfter' => array_intersect_key(WorksheetSheet::FIELDS, ['summary' => 1, 'references' => 1]),
            'fileLimits' => ['files' => AssignmentRepository::FILE_MAX, 'mb' => (int) (AssignmentRepository::FILE_MAX_BYTES / 1048576)],
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

        if ($data === [] && (int) $request->getHeaderLine('Content-Length') > 0) {
            // ใหญ่เกิน post_max_size — PHP ทิ้งทั้งฟอร์มรวมถึง _token จึงไม่ใช่เรื่องเซสชันหมดอายุ
            Flash::error('ไฟล์แนบรวมกันใหญ่เกินที่เซิร์ฟเวอร์รับได้ กรุณาลดจำนวนหรือขนาดไฟล์แล้วบันทึกใหม่');

            return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
        }
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

        // ตรวจไฟล์แนบทั้งหมดก่อนบันทึกอะไร — ผิดไฟล์เดียวก็ไม่บันทึกทั้งชุด
        $removeFiles = array_map('intval', (array) ($data['remove_files'] ?? []));
        $kept = $assignment === null ? 0 : count(array_filter(
            $this->assignments->files((int) $assignment['id']),
            static fn (array $f): bool => !in_array((int) $f['id'], $removeFiles, true)
        ));
        $uploads = $this->postedFiles($request);
        if (is_array($uploads) && $uploads !== [] && !$this->assignments->filesReady()) {
            $uploads = 'ระบบยังไม่พร้อมรับไฟล์แนบ (ผู้ดูแลระบบต้องรันการอัปเดตฐานข้อมูลก่อน)';
        }
        if (is_string($uploads) || $kept + count($uploads) > AssignmentRepository::FILE_MAX) {
            Flash::error(is_string($uploads) ? $uploads : 'แนบไฟล์ได้ไม่เกิน ' . AssignmentRepository::FILE_MAX . ' ไฟล์ต่อ' . $noun);
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
        } elseif (isset($data['sheet_present']) && $this->assignments->sheetReady()) {
            $fields['sheet'] = WorksheetSheet::fromPost($data);
        }

        if ($assignment !== null) {
            $id = (int) $assignment['id'];
            $this->assignments->update($id, $fields);
            Flash::success('บันทึก' . $noun . ' ' . $title . ' แล้ว');
        } else {
            $id = $this->assignments->create($fields + [
                'course_id' => (int) $course['id'],
                'unit_id' => $unit === null ? null : (int) $unit['id'],
                'kind' => $isTask ? 'task' : 'worksheet',
                'created_by' => (int) $user['id'],
            ]);
            Flash::success('เพิ่ม' . $noun . ' ' . $title . ' แล้ว');
        }

        if ($removeFiles !== [] || $uploads !== []) {
            try {
                $this->assignments->removeFiles($id, $removeFiles);
                $dir = AssignmentRepository::fileDir($id);
                foreach ($uploads as [$file, $name, $mime]) {
                    $stored = $dir . '/' . bin2hex(random_bytes(8)) . '.' . AssignmentRepository::FILE_TYPES[$mime];
                    $file->moveTo(Paths::storage('uploads/' . $stored));
                    $this->assignments->addFile($id, [
                        'original_name' => mb_substr($name !== '' ? $name : basename($stored), 0, 255),
                        'stored_path' => $stored,
                        'mime_type' => $mime,
                        'size_bytes' => (int) $file->getSize(),
                    ]);
                }
            } catch (Throwable $e) {
                $this->logger->error('Assignment file save failed: ' . $e->getMessage(), ['assignment' => $id, 'exception' => $e]);
                Flash::error('บันทึก' . $noun . 'แล้ว แต่แนบไฟล์ไม่สำเร็จ กรุณาลองแนบใหม่อีกครั้ง');

                return $this->backToForm($response, $course, $unit, $id, $isTask);
            }
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

        // ค่าที่กรอกอยู่บนฟอร์ม (สคริปต์ในหน้าคัดลอกมาให้) — ผู้ช่วยเติมเฉพาะส่วนที่ยังว่าง ไม่แทนที่ของเดิม
        $sheetOn = !$isTask && $this->assignments->sheetReady();
        $mine = [
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 191),
            'description' => trim(str_replace("\r\n", "\n", (string) ($data['description'] ?? ''))),
        ];
        $mineSheet = $sheetOn ? WorksheetSheet::posted($data) : [];
        $hadContent = implode('', $mine) !== '' || implode('', $mineSheet) !== '';

        try {
            $objects = $this->drafter->objects((int) $user['id'], $system, $this->draftSpec($course, $unit, $maxScore, $data, $sheetOn) + [
                'existing' => $mine + ['sheet' => $mineSheet],
            ], 3, (string) ($data['ai'] ?? ''));
            $draft = $this->firstDraft($objects);
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
            'title' => $mine['title'] !== '' ? $mine['title'] : mb_substr(trim((string) ($draft['title'] ?? '')), 0, 191),
            'description' => $mine['description'] !== '' ? $mine['description'] : $this->describe($draft),
            'sheet' => $sheetOn ? WorksheetSheet::fill($mineSheet, WorksheetSheet::fromDraft($draft)) : null,
            'max_score' => $maxScore,
            'due_at' => (string) ($data['due_at'] ?? ''),
            'allow_late' => isset($data['allow_late']) ? '1' : '',
            'unit_id' => $unit === null ? '' : (string) $unit['id'],
        ];
        $this->auth->log('ai.assignment.draft', $unit === null ? 'course#' . $course['id'] : 'unit#' . $unit['id']);
        Flash::success($hadContent
            ? 'ผู้ช่วยเติมส่วนที่ยังว่างของ' . ($isTask ? 'งาน' : 'ใบงาน') . 'ให้แล้ว (ส่วนที่กรอกไว้เดิมไม่ถูกแก้) · ตรวจแล้วกดบันทึก'
            : 'ผู้ช่วยร่าง' . ($isTask ? 'งาน' : 'ใบงาน') . 'ให้แล้ว · ตรวจแก้ได้ตามต้องการ แล้วกดบันทึก');

        return $this->backToForm($response, $course, $unit, $args['id'] ?? null, $isTask);
    }

    /**
     * ร่างใบงานของทุกหน่วยจากแท็บใบงาน: หน้าเว็บเรียกทีละหน่วย (public/assets/js/worksheets-autodraft.js)
     * พร้อมสิ่งที่ครูสั่งเพิ่มเติมครั้งเดียว · หน่วยที่มีใบงานแล้ว/หน่วยสอบข้าม
     * หน่วยที่เผยแพร่อยู่กลับเป็นฉบับร่าง ให้ครูตรวจใบงานก่อนนักเรียนเห็น · ตอบ JSON
     */
    public function autoDraft(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $unit] = $this->context($request, $args);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'stop' => true, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'], 419);
        }
        if ((int) ($course['ai_lesson_plan_enabled'] ?? 1) !== 1) {
            return $this->json($response, ['ok' => false, 'stop' => true, 'error' => 'ผู้ดูแลระบบปิดฟังก์ชัน AI ของรายวิชานี้ไว้'], 403);
        }
        if (UnitRepository::isExam($unit)) {
            return $this->json($response, ['ok' => true, 'skipped' => 'exam']);
        }
        if ($this->assignments->forUnit((int) $unit['id']) !== []) {
            return $this->json($response, ['ok' => true, 'skipped' => 'has_worksheet']);
        }

        // งานยาว — ทำต่อแม้ปิดหน้า (Drafter ปลดล็อก session ระหว่างรอเอง)
        ignore_user_abort(true);
        @set_time_limit(0);

        $maxScore = max(1, min(999, (float) ($data['max_score'] ?? 10)));
        $sheetOn = $this->assignments->sheetReady();
        try {
            $objects = $this->drafter->objects((int) $user['id'],
                'คุณเป็นผู้ช่วยครูอาชีวศึกษา ออกแบบใบงานภาษาไทยที่สั่งงานเป็นรูปธรรม ตอบกลับเป็น JSON อ็อบเจกต์เดียว',
                $this->draftSpec($course, $unit, $maxScore, $data, $sheetOn), 3, (string) ($data['ai'] ?? ''));
            $draft = $this->firstWithTitle($objects);
        } catch (AiUnavailableException $e) {
            // AI ใช้ไม่ได้ (โควตาหมด/เครื่องปิด/คีย์ผิด) — หน่วยถัดไปก็จะไม่ได้เหมือนกัน หยุดทั้งชุด
            return $this->json($response, ['ok' => false, 'stop' => true, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->error('ร่างใบงานทุกหน่วย: หน่วยนี้ไม่สำเร็จ: ' . $e->getMessage(), ['unit_id' => (int) $unit['id']]);

            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยร่างใบงานหน่วยนี้ไม่สำเร็จ']);
        }
        if ($draft === null) {
            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยไม่ได้ร่างใบงานออกมา']);
        }

        // ระหว่างรอ AI อาจมีคนเพิ่มใบงานไปแล้ว (เปิดสองแท็บ) — ไม่สร้างซ้ำ
        if ($this->assignments->forUnit((int) $unit['id']) !== []) {
            return $this->json($response, ['ok' => true, 'skipped' => 'has_worksheet']);
        }
        $title = mb_substr(trim((string) $draft['title']), 0, 191);
        $id = $this->assignments->create([
            'title' => $title,
            'description' => $this->describe($draft) ?: null,
            'due_at' => null,
            'max_score' => $maxScore,
            'allow_late' => 0,
            'course_id' => (int) $course['id'],
            'unit_id' => (int) $unit['id'],
            'kind' => 'worksheet',
            'created_by' => (int) $user['id'],
        ] + ($sheetOn ? ['sheet' => WorksheetSheet::encode(WorksheetSheet::fromDraft($draft))] : []));
        $unpublished = ($unit['review_status'] ?? '') === 'published';
        if ($unpublished) {
            $this->units->update((int) $unit['id'], ['review_status' => 'draft']);
        }
        $this->auth->log('ai.assignment.auto', 'assignment#' . $id, ['unit' => (int) $unit['id']]);

        return $this->json($response, [
            'ok' => true,
            'title' => $title,
            'unpublished' => $unpublished,
            'chip' => $this->drafter->lastRoute?->chip,
            'editUrl' => Url::to('/courses/' . $course['id'] . '/units/' . $unit['id'] . '/assignments/' . $id . '/edit'),
        ]);
    }

    /** @param array<string,mixed> $body */
    private function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
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
     * ข้อมูลที่ผู้ช่วยใช้ร่างใบงาน: หน่วยการเรียน (สาระสำคัญ จุดประสงค์ สมรรถนะ ผลลัพธ์ระดับหน่วย อ้างอิงมาตรฐาน)
     * งานที่ไม่ผูกหน่วยใช้คำอธิบายรายวิชาแทน · sheet = ขอส่วนประกอบใบงานตามแบบฟอร์มแผน 2567 ด้วย
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function draftSpec(array $course, ?array $unit, float $maxScore, array $data, bool $sheet): array
    {
        $std = UnitPlanFields::decode($unit['standard_ref'] ?? null);
        $standard = trim(implode("\n", array_filter([
            $std['uoc'] !== '' ? 'หน่วยสมรรถนะ: ' . $std['uoc'] : '',
            $std['eoc'] !== [] ? 'สมรรถนะย่อย: ' . implode(' · ', $std['eoc']) : '',
            ($std['career'] ?: ($course['career_group'] ?? '')) !== '' ? 'กลุ่มอาชีพ: ' . ($std['career'] ?: $course['career_group']) : '',
        ])));

        return [
            'task' => 'assignment',
            'course_code' => $course['code'],
            'course_name' => $course['name'],
            'unit' => $unit['title'] ?? 'ทั้งรายวิชา',
            'key_content' => $unit['key_content'] ?? ($course['description'] ?? ''),
            'objectives' => (string) ($unit['objectives'] ?? ''),
            'competencies' => (string) ($unit['competencies'] ?? ''),
            'outcome' => (string) ($unit['learning_outcome'] ?? ''),
            'standard' => $standard,
            'max_score' => $maxScore,
            'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 2000),
            'sheet' => $sheet,
        ];
    }

    /**
     * ผลร่างชิ้นแรกที่ใช้ได้ (ครูมีชื่อใบงานแล้ว โมเดลอาจไม่ส่ง title มา)
     *
     * @param list<array<string,mixed>> $objects
     * @return array<string,mixed>|null
     */
    private function firstDraft(array $objects): ?array
    {
        foreach ($objects as $obj) {
            foreach (['title', 'steps', 'objective', 'outcome', 'tools'] as $key) {
                if (!empty($obj[$key])) {
                    return $obj;
                }
            }
        }

        return null;
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
            'sheet' => WorksheetSheet::decode($assignment['sheet'] ?? null),
        ];

        if ($draft === null) {
            return $base;
        }
        // ผลร่างของผู้ช่วย (sheet) หรือฟอร์มที่ส่งไม่ผ่าน (sheet_*)
        $sheet = is_array($draft['sheet'] ?? null) ? $draft['sheet'] + $base['sheet']
            : (isset($draft['sheet_present']) ? WorksheetSheet::posted($draft) : $base['sheet']);

        return [
            'title' => (string) ($draft['title'] ?? $base['title']),
            'description' => (string) ($draft['description'] ?? $base['description']),
            'max_score' => (float) ($draft['max_score'] ?? $base['max_score']),
            'due_at' => (string) ($draft['due_at'] ?? $base['due_at']),
            'allow_late' => (string) ($draft['allow_late'] ?? '') !== '',
            'unit_id' => (string) ($draft['unit_id'] ?? $base['unit_id']),
            'sheet' => $sheet,
        ];
    }

    /**
     * ไฟล์ที่ครูเลือกแนบ ตรวจขนาดและชนิดจริงแล้ว
     *
     * @return list<array{0:UploadedFileInterface,1:string,2:string}>|string ข้อความผิดพลาดเมื่อมีไฟล์ใช้ไม่ได้
     */
    private function postedFiles(Request $request): array|string
    {
        $posted = $request->getUploadedFiles()['files'] ?? [];
        $mb = (int) (AssignmentRepository::FILE_MAX_BYTES / 1048576);
        $uploads = [];
        foreach (is_array($posted) ? $posted : [$posted] as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = (string) $file->getClientFilename();
            $error = match (true) {
                in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true),
                (int) $file->getSize() > AssignmentRepository::FILE_MAX_BYTES => 'ใหญ่เกิน ' . $mb . ' MB',
                $file->getError() !== UPLOAD_ERR_OK => 'อัปโหลดไม่สำเร็จ',
                default => null,
            };
            $mime = null;
            if ($error === null) {
                $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getStream()->getMetadata('uri'));
                $mime = AssignmentRepository::fileType($detected, $name);
                if ($mime === null) {
                    $error = 'เป็นไฟล์ชนิดที่ไม่รับ (รับ PDF, รูปภาพ, Word, Excel, PowerPoint, ZIP, TXT)';
                }
            }
            if ($error !== null) {
                return 'ไฟล์ "' . mb_substr($name, 0, 60) . '" ' . $error;
            }
            $uploads[] = [$file, $name, (string) $mime];
        }

        return $uploads;
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
