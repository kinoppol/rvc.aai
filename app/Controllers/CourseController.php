<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\AssignmentRepository;
use App\Domain\CourseFeedRepository;
use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\PlanSignerRepository;
use App\Domain\QuizRepository;
use App\Domain\StudentAccountRepository;
use App\Domain\UnitRepository;
use App\Support\Cover;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Semester;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class CourseController
{
    private const TABS = ['units', 'feed', 'quizzes', 'worksheets', 'tasks', 'students', 'scores', 'reports'];

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly QuizRepository $quizzes,
        private readonly AssignmentRepository $assignments,
        private readonly EnrollmentRepository $enrollments,
        private readonly StudentAccountRepository $studentAccounts,
        private readonly Auth $auth,
        private readonly Db $db,
        private readonly PlanSignerRepository $signers,
        private readonly CourseFeedRepository $feed,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $teacherId = (int) $user['id'];
        $currentTermId = $this->courses->currentTermId();
        $terms = $this->courses->teacherTerms($teacherId, $currentTermId);

        // ?term=<id> ภาคเรียนที่เลือก · ?term=all ทุกภาคเรียน · ไม่ระบุ = ภาคเรียนปัจจุบัน
        $raw = (string) ($request->getQueryParams()['term'] ?? '');
        $known = array_column($terms, 'id');
        $selected = match (true) {
            $raw === 'all' => 'all',
            ctype_digit($raw) && in_array((int) $raw, $known, true) => (int) $raw,
            default => $currentTermId ?? 'all',
        };
        $termId = $selected === 'all' ? null : $selected;

        return $this->view->render($response, 'courses/index', [
            'page' => 'courses',
            'courses' => $this->courses->forTeacherInTerm($teacherId, $termId, $currentTermId),
            'terms' => $terms,
            'selectedTerm' => $selected,
            'currentTermId' => $currentTermId,
            'isCurrentTerm' => $termId !== null && $termId === $currentTermId,
        ]);
    }

    /** ครูเผยแพร่/ยกเลิกเผยแพร่รายวิชาทีละวิชาในหน้าแรกสาธารณะ */
    public function landingVisibility(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $response->withHeader('Location', Url::to('/courses'))->withStatus(302);
        }

        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);
        $visible = ($data['visible'] ?? '') === '1';
        $this->courses->setLandingForCourse((int) $course['id'], $visible);

        Flash::success($visible
            ? 'เผยแพร่รายวิชา ' . $course['name'] . ' ในหน้าแรกสาธารณะแล้ว'
            : 'ยกเลิกการเผยแพร่รายวิชา ' . $course['name'] . ' ในหน้าแรกแล้ว');

        return $response->withHeader('Location', Url::to('/courses'))->withStatus(302);
    }

    /** เปิด/ปิดการเข้าร่วมด้วยรหัส หรือสุ่มรหัสใหม่เมื่อรหัสเดิมหลุดไปนอกห้อง */
    public function joinSettings(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        $back = $response->withHeader('Location', Url::to('/courses/' . (int) $args['id'] . '?tab=students'))->withStatus(302);

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $back;
        }

        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);

        $do = (string) ($data['do'] ?? '');
        if ($do === 'regenerate') {
            $this->courses->regenerateJoinCode((int) $course['id']);
            Flash::success('สร้างรหัสเข้าร่วมใหม่แล้ว · รหัสและลิงก์เดิมใช้ไม่ได้อีก');
        } elseif ($do === 'open' || $do === 'close') {
            $this->courses->setJoinEnabled((int) $course['id'], $do === 'open');
            Flash::success($do === 'open'
                ? 'เปิดให้นักเรียนเข้าร่วมด้วยรหัสแล้ว'
                : 'ปิดการเข้าร่วมด้วยรหัสแล้ว · นักเรียนที่อยู่ในรายวิชาแล้วยังเรียนได้ตามปกติ');
        }

        return $back;
    }

    /**
     * ครูเพิ่มนักเรียนเข้ารายวิชาโดยระบุอีเมล (ทีละหลายอีเมลได้)
     * ค้นเฉพาะบัญชีนักเรียนที่มีอยู่แล้วในสถานศึกษาเดียวกัน — ไม่สร้างบัญชีใหม่จากอีเมล
     */
    public function addStudents(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        $back = $response->withHeader('Location', Url::to('/courses/' . (int) $args['id'] . '?tab=students'))->withStatus(302);

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $back;
        }

        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);
        $courseId = (int) $course['id'];

        $emails = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', (string) ($data['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $email = strtolower(trim($raw, " \t<>\"'"));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $raw;
                continue;
            }
            $emails[$email] = true;
        }
        $emails = array_slice(array_keys($emails), 0, 200);

        if ($emails === []) {
            Flash::error($invalid === []
                ? 'กรุณากรอกอีเมลของนักเรียนอย่างน้อยหนึ่งอีเมล'
                : 'รูปแบบอีเมลไม่ถูกต้อง: ' . implode(', ', array_slice($invalid, 0, 10)));

            return $back;
        }

        // จำกัดอยู่ในสถานศึกษาของครู (ข้ามเมื่อบัญชีครูยังไม่ระบุสถานศึกษา — แบบเดียวกับการเข้าร่วมด้วยรหัส)
        $institutionId = $this->db->value('SELECT institution_id FROM {users} WHERE id = ?', [(int) $user['id']]);
        $institutionId = $institutionId === null ? null : (int) $institutionId;

        $added = [];
        $already = [];
        $notFound = [];
        $ambiguous = [];
        foreach ($emails as $email) {
            $matches = $this->studentAccounts->activeByEmail($email, $institutionId);
            if ($matches === []) {
                $notFound[] = $email;
                continue;
            }
            if (count($matches) > 1) {
                $ambiguous[] = $email;
                continue;
            }

            $student = $matches[0];
            if ($this->enrollments->isEnrolled($courseId, (int) $student['id'])) {
                $already[] = $student['full_name'];
                continue;
            }

            $this->enrollments->enroll($courseId, (int) $student['id']);
            if ($this->enrollments->isEnrolled($courseId, (int) $student['id'])) {
                $added[] = $student['full_name'];
            } else {
                $already[] = $student['full_name'] . ' (เรียนจบแล้ว)';
            }
        }

        if ($added !== []) {
            $this->auth->log('course.add_students', 'course#' . $courseId, ['count' => count($added)]);
            Flash::success(sprintf('เพิ่มนักเรียน %d คนเข้ารายวิชาแล้ว: %s', count($added), $this->nameList($added)));
        }
        if ($already !== []) {
            Flash::warning('อยู่ในรายวิชาแล้ว: ' . $this->nameList($already));
        }
        if ($notFound !== []) {
            Flash::error('ไม่พบบัญชีนักเรียนที่ใช้อีเมลนี้' . ($institutionId !== null ? 'ในสถานศึกษาของคุณ' : '')
                . ': ' . $this->nameList($notFound) . ' · ตรวจอีเมล หรือเพิ่มอีเมลให้นักเรียนที่เมนูนักเรียนก่อน');
        }
        if ($ambiguous !== []) {
            Flash::error('อีเมลนี้มีนักเรียนใช้ร่วมกันหลายบัญชี จึงไม่ได้เพิ่มให้: ' . $this->nameList($ambiguous)
                . ' · ให้นักเรียนเข้าร่วมด้วยรหัสแทน');
        }
        if ($invalid !== []) {
            Flash::error('ข้ามอีเมลที่รูปแบบไม่ถูกต้อง: ' . $this->nameList($invalid));
        }

        return $back;
    }

    /**
     * เพิ่มนักเรียนที่ครูติ๊กเลือกจากรายชื่อกลุ่มเรียน (กลุ่มของรายวิชา หรือกลุ่มอื่นที่ครูเลือกเพิ่ม)
     * รับเฉพาะ id ที่อยู่ในกลุ่มที่ระบุจริง กันส่ง id อื่นเข้ามา
     */
    public function addClassroomStudents(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        $groupId = (int) ($data['classroom_id'] ?? 0);
        $back = $response->withHeader(
            'Location',
            Url::to('/courses/' . (int) $args['id'] . '?tab=students' . ($groupId > 0 ? '&group=' . $groupId : ''))
        )->withStatus(302);

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $back;
        }

        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);
        $group = $groupId > 0 ? $this->db->first('SELECT id, name FROM {classrooms} WHERE id = ?', [$groupId]) : null;
        if ($group === null) {
            Flash::error('กรุณาเลือกกลุ่มเรียน');

            return $back;
        }

        $picked = array_flip(array_map('intval', (array) ($data['student_ids'] ?? [])));
        $added = 0;
        foreach ($this->studentAccounts->inClassroom((int) $group['id'], (int) $course['id']) as $s) {
            if (!isset($picked[(int) $s['id']]) || in_array($s['enrollment_status'], ['active', 'completed'], true)) {
                continue;
            }
            $this->enrollments->enroll((int) $course['id'], (int) $s['id']);
            $added++;
        }

        if ($added > 0) {
            $this->auth->log('course.add_classroom_students', 'course#' . $course['id'], ['count' => $added, 'classroom_id' => (int) $group['id']]);
            Flash::success(sprintf('เพิ่มนักเรียน %d คนจากกลุ่ม %s เข้ารายวิชาแล้ว', $added, $group['name']));
        } else {
            Flash::warning('ไม่ได้เพิ่มนักเรียน — เลือกนักเรียนที่ยังไม่อยู่ในรายวิชาก่อน');
        }

        return $back;
    }

    /**
     * รายชื่อนักเรียนของกลุ่มเรียนที่เลือก (ค่าเริ่มต้น = กลุ่มของรายวิชา) สร้างอีเมลให้คนที่ยังไม่มีก่อนแสดง
     *
     * @param array<string,mixed> $course
     * @return array<string,mixed>
     */
    private function classroomRoster(array $course, int $groupId): array
    {
        $groupId = $groupId > 0 ? $groupId : (int) ($course['classroom_id'] ?? 0);
        $group = $groupId > 0 ? $this->db->first('SELECT id, name FROM {classrooms} WHERE id = ?', [$groupId]) : null;

        $roster = $group === null
            ? []
            : $this->withEmails($this->studentAccounts->inClassroom((int) $group['id'], (int) $course['id']));
        $pending = count(array_filter($roster, static fn (array $s): bool => !in_array($s['enrollment_status'], ['active', 'completed'], true)));

        return [
            'rosterGroup'   => $group,
            'roster'        => $roster,
            'rosterPending' => $pending,
            'classrooms'    => $this->courses->classrooms(),
        ];
    }

    /**
     * @param list<array<string,mixed>> $students
     * @return list<array<string,mixed>> รายชื่อเดิม โดยเติมอีเมลที่เพิ่งสร้างให้
     */
    private function withEmails(array $students): array
    {
        $missing = array_values(array_map(
            static fn (array $s): int => (int) $s['id'],
            array_filter($students, static fn (array $s): bool => trim((string) ($s['email'] ?? '')) === '')
        ));
        if ($missing === [] || $this->studentAccounts->fillMissingEmails($missing) === 0) {
            return $students;
        }

        $emails = [];
        $in = implode(',', array_fill(0, count($missing), '?'));
        foreach ($this->db->all("SELECT id, email FROM {users} WHERE id IN ($in)", $missing) as $r) {
            $emails[(int) $r['id']] = $r['email'];
        }
        foreach ($students as $i => $s) {
            if (isset($emails[(int) $s['id']])) {
                $students[$i]['email'] = $emails[(int) $s['id']];
            }
        }

        return $students;
    }

    /** @param list<string> $items */
    private function nameList(array $items): string
    {
        $shown = implode(', ', array_slice($items, 0, 8));

        return count($items) > 8 ? $shown . sprintf(' และอีก %d รายการ', count($items) - 8) : $shown;
    }

    /** ฟอร์มเพิ่มรายวิชาใหม่ หรือแก้ไขรายวิชาเดิมของครูคนนี้ */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = isset($args['id'])
            ? $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id'])
            : null;

        return $this->view->render($response, 'courses/edit', [
            'page' => 'courses',
            'course' => $course,
            // ชุดสีหน้าปก + สีที่ระบบสุ่มให้วิชานี้เมื่อครูไม่ได้เลือกเอง (วิชาใหม่สุ่มสีให้ก่อน)
            'coverPalette' => Cover::PALETTE,
            'coverAuto' => $course !== null
                ? Cover::color(['id' => $course['id']])
                : Cover::hexes()[array_rand(Cover::hexes())],
            'terms' => $this->courses->terms(),
            'classrooms' => $this->courses->classrooms(),
            'currentTermId' => $this->courses->currentTermId(),
            // หัวหน้าแผนกผู้ลงนามในแผนการสอน · รายวิชาใหม่ใช้คนที่ครูเลือกไว้ในรายวิชาล่าสุด
            'staff' => $this->signers->ready() ? $this->signers->staff() : null,
            'deptHeadId' => $course !== null
                ? (int) ($course['dept_head_id'] ?? 0)
                : $this->signers->lastDeptHead((int) $user['id']),
        ]);
    }

    /** บันทึกรายวิชา — ใช้ทั้งตอนเพิ่มใหม่และตอนแก้ไข */
    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response);
        }

        $course = isset($args['id'])
            ? $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id'])
            : null;

        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $termId = ((int) ($data['term_id'] ?? 0)) ?: null;
        $classroomId = ((int) ($data['classroom_id'] ?? 0)) ?: null;

        if ($code === '' || $name === '') {
            Flash::error('กรุณากรอกรหัสวิชาและชื่อวิชาให้ครบ');

            return $this->back($response, $course, true);
        }

        if ($this->courses->codeTaken((int) $user['id'], $code, $termId, $classroomId, $course['id'] ?? null)) {
            Flash::error('คุณมีรายวิชารหัส ' . $code . ' ในภาคเรียนและกลุ่มเรียนนี้อยู่แล้ว');

            return $this->back($response, $course, true);
        }

        $fields = [
            'code' => $code,
            'name' => $name,
            'credits' => max(0, min(9.9, (float) ($data['credits'] ?? 3))),
            'theory_hours' => max(0, min(255, (int) ($data['theory_hours'] ?? 0))),
            'practice_hours' => max(0, min(255, (int) ($data['practice_hours'] ?? 0))),
            // ว่าง = ใช้จำนวนสัปดาห์ปกติตามระดับชั้นจากรหัสวิชา (Semester::defaultWeeks)
            'weeks' => ((int) ($data['weeks'] ?? 0)) > 0 ? min(Semester::MAX_WEEKS, (int) $data['weeks']) : null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'objectives' => trim((string) ($data['objectives'] ?? '')) ?: null,
            'competencies' => trim((string) ($data['competencies'] ?? '')) ?: null,
            // ข้อมูลหน้าปก/ลักษณะรายวิชาของแผนการสอนที่สั่งพิมพ์
            'program_type' => mb_substr(trim((string) ($data['program_type'] ?? '')), 0, 191) ?: null,
            'major' => mb_substr(trim((string) ($data['major'] ?? '')), 0, 191) ?: null,
            'plan_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['plan_date'] ?? '')) === 1
                && checkdate((int) substr($data['plan_date'], 5, 2), (int) substr($data['plan_date'], 8, 2), (int) substr($data['plan_date'], 0, 4))
                ? $data['plan_date'] : null,
            'learning_outcomes' => trim((string) ($data['learning_outcomes'] ?? '')) ?: null,
            'term_id' => $termId,
            'classroom_id' => $classroomId,
            // สีหน้าปก — ว่าง = ให้ระบบสุ่มจาก id วิชา (สีเดิมเสมอ)
            'cover_color' => Cover::normalise((string) ($data['cover_color'] ?? '')),
        ];

        // ก่อนรันไมเกรชัน 000128 ยังไม่มีคอลัมน์นี้ — ข้ามไปให้บันทึกรายวิชาได้ตามปกติ
        if ($this->signers->ready()) {
            $fields['dept_head_id'] = $this->signers->validStaff((int) ($data['dept_head_id'] ?? 0));
        }

        if ($course === null) {
            $id = $this->courses->create((int) $user['id'], $fields);
            Flash::success('เพิ่มรายวิชา ' . $name . ' แล้ว');
        } else {
            $id = (int) $course['id'];
            $this->courses->update($id, $fields);
            Flash::success('บันทึกการแก้ไขรายวิชาแล้ว');
        }

        $coverError = $this->saveCover($request, $id, (string) ($course['cover_image'] ?? ''), ($data['remove_cover'] ?? '') === '1');
        if ($coverError !== null) {
            Flash::error($coverError);
        }

        return $response->withHeader('Location', Url::to('/courses/' . $id))->withStatus(302);
    }

    /**
     * รับรูปหน้าปกที่อัปโหลด (jpg/png/webp ไม่เกิน 3 MB) หรือลบรูปเดิม — คืนข้อความเมื่อมีปัญหา
     * ตรวจชนิดไฟล์จากเนื้อไฟล์จริง ไม่เชื่อนามสกุลหรือชนิดที่เบราว์เซอร์บอกมา
     */
    private function saveCover(Request $request, int $courseId, string $current, bool $remove): ?string
    {
        $file = $request->getUploadedFiles()['cover'] ?? null;
        $hasUpload = $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE;

        if (!$hasUpload) {
            if ($remove && $current !== '') {
                $this->deleteCoverFile($current);
                $this->courses->update($courseId, ['cover_image' => null]);
            }

            return null;
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'อัปโหลดรูปหน้าปกไม่สำเร็จ ลองใหม่อีกครั้ง';
        }
        if (($file->getSize() ?? 0) > 3 * 1024 * 1024) {
            return 'รูปหน้าปกใหญ่เกิน 3 MB · ย่อรูปก่อนแล้วอัปโหลดใหม่';
        }

        $dir = Cover::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'สร้างโฟลเดอร์เก็บรูปหน้าปกไม่ได้ · แจ้งผู้ดูแลระบบ';
        }

        $tmp = $dir . '/.upload-' . bin2hex(random_bytes(6));
        $file->moveTo($tmp);

        $ext = match ((new \finfo(FILEINFO_MIME_TYPE))->file($tmp)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($ext === null || @getimagesize($tmp) === false) {
            @unlink($tmp);

            return 'รูปหน้าปกต้องเป็นไฟล์ JPG, PNG หรือ WEBP';
        }

        $name = $courseId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        rename($tmp, $dir . '/' . $name);
        if ($current !== '') {
            $this->deleteCoverFile($current);
        }
        $this->courses->update($courseId, ['cover_image' => $name]);

        return null;
    }

    private function deleteCoverFile(string $file): void
    {
        if ($file !== '' && basename($file) === $file && is_file(Cover::dir() . '/' . $file)) {
            @unlink(Cover::dir() . '/' . $file);
        }
    }

    /** เก็บรายวิชาเข้าคลัง — ไม่ลบ เพราะบทเรียนและคะแนนของนักเรียนยังผูกอยู่ */
    public function archive(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->back($response);
        }

        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);
        $this->courses->archive((int) $course['id']);
        Flash::success('เก็บรายวิชา ' . $course['name'] . ' เข้าคลังแล้ว · ข้อมูลบทเรียนและคะแนนยังอยู่ครบ');

        return $this->back($response);
    }

    /** ย้ายแบบทดสอบที่ซ้ำหรือเลิกใช้ลงถังขยะ · ข้อสอบและผลสอบยังอยู่ กู้คืนได้ภายใน 30 วัน */
    public function deleteQuiz(Request $request, Response $response, array $args): Response
    {
        return $this->quizTrashAction($request, $response, $args, 'trash');
    }

    /** กู้แบบทดสอบคืนจากถังขยะ */
    public function restoreQuiz(Request $request, Response $response, array $args): Response
    {
        return $this->quizTrashAction($request, $response, $args, 'restore');
    }

    /** ลบถาวรทันทีจากถังขยะ (ไม่ต้องรอครบ 30 วัน) · ผลสอบของนักเรียนถูกลบไปด้วย */
    public function destroyQuiz(Request $request, Response $response, array $args): Response
    {
        return $this->quizTrashAction($request, $response, $args, 'destroy');
    }

    /**
     * ถังขยะ (เมนูด้านข้าง): แบบทดสอบที่ลบจากทุกรายวิชาของครู จัดกลุ่มตามรายวิชา
     * ล้างชุดที่ครบ 30 วันก่อน แล้วบอกจำนวนวันที่เหลือก่อนถูกลบถาวร
     */
    public function trash(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $this->quizzes->purgeExpired();

        $groups = [];
        foreach ($this->quizzes->trashedForTeacher((int) $user['id']) as $q) {
            $q['days_left'] = max(0, (int) ceil((strtotime((string) $q['purge_at']) - time()) / 86400));
            $groups[(int) $q['course_id']] ??= ['id' => (int) $q['course_id'], 'code' => $q['course_code'], 'name' => $q['course_name'], 'quizzes' => []];
            $groups[(int) $q['course_id']]['quizzes'][] = $q;
        }

        return $this->view->render($response, 'courses/trash', [
            'page' => 'trash',
            'groups' => array_values($groups),
        ]);
    }

    /** @param 'trash'|'restore'|'destroy' $action */
    private function quizTrashAction(Request $request, Response $response, array $args, string $action): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);
        // ย้ายลงถังขยะจากแท็บแบบทดสอบ · กู้คืน/ลบถาวรจากหน้าถังขยะ (เมนูด้านข้าง)
        $tabUrl = Url::to($action === 'trash' ? '/courses/' . $course['id'] . '?tab=quizzes' : '/trash');

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่');

            return $response->withHeader('Location', $tabUrl)->withStatus(302);
        }

        $quizId = (int) $args['quizId'];
        $quiz = $action === 'trash'
            ? $this->quizzes->find($quizId)
            : $this->quizzes->findTrashed($quizId, (int) $course['id']);
        if ($quiz === null || (int) $quiz['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }

        $students = $this->db->int('SELECT COUNT(DISTINCT student_id) FROM {quiz_attempts} WHERE quiz_id = ?', [$quizId]);
        $meta = ['title' => $quiz['title'], 'students' => $students];

        switch ($action) {
            case 'trash':
                $this->quizzes->trash($quizId);
                $this->auth->log('quiz.trash', 'quiz#' . $quizId, $meta);
                Flash::success(sprintf(
                    'ย้าย "%s" ไปที่ถังขยะแล้ว · กู้คืนได้จากเมนูถังขยะภายใน %d วัน ก่อนถูกลบถาวร',
                    $quiz['title'],
                    QuizRepository::TRASH_DAYS
                ));
                break;
            case 'restore':
                $this->quizzes->restore($quizId);
                $this->auth->log('quiz.restore', 'quiz#' . $quizId, $meta);
                Flash::success('กู้คืน "' . $quiz['title'] . '" แล้ว · ผลสอบของนักเรียนกลับมาครบ');
                break;
            default:
                $this->quizzes->destroy($quizId);
                $this->auth->log('quiz.delete', 'quiz#' . $quizId, $meta);
                Flash::success('ลบ "' . $quiz['title'] . '" ถาวรแล้ว'
                    . ($students > 0 ? sprintf(' · รวมผลสอบของนักเรียน %d คน', $students) : ''));
        }

        return $response->withHeader('Location', $tabUrl)->withStatus(302);
    }

    /**
     * กลับไปหน้าฟอร์มเดิม เพื่อให้ครูแก้ข้อมูลต่อได้ทันทีเมื่อบันทึกไม่ผ่าน
     *
     * @param array<string,mixed>|null $course
     */
    private function back(Response $response, ?array $course = null, bool $creating = false): Response
    {
        $path = match (true) {
            $course !== null => '/courses/' . $course['id'] . '/edit',
            $creating => '/courses/new',
            default => '/courses',
        };

        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);

        $tab = (string) ($request->getQueryParams()['tab'] ?? 'units');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'units';
        }

        $data = [
            'page' => 'courses',
            'course' => $course,
            'tab' => $tab,
        ];

        $data += match ($tab) {
            'units'    => ['units' => $this->decorateUnits($this->units->forCourse($course['id']))],
            'feed'     => [
                'feedReady' => $this->feed->ready(),
                'posts' => $posts = $this->feed->forCourse((int) $course['id']),
                'seenCounts' => $this->feed->seenCounts((int) $course['id'], $posts),
            ],
            'quizzes'  => ['quizGroups' => $this->quizGroups((int) $course['id'])],
            'worksheets' => [
                'worksheets' => $this->assignments->forCourse((int) $course['id'], 'worksheet'),
                'lessonUnits' => array_values(array_filter(
                    $this->units->forCourse((int) $course['id']),
                    static fn (array $u): bool => !UnitRepository::isExam($u)
                )),
            ],
            'tasks'    => ['tasks' => $this->assignments->forCourse((int) $course['id'], 'task')],
            'students' => $this->classroomRoster($course, (int) ($request->getQueryParams()['group'] ?? 0)) + [
                'students' => $this->withEmails($this->enrollments->studentsInCourse($course['id'])),
                'joinCode' => $code = $this->courses->joinCode((int) $course['id']),
                'joinUrl' => (string) $request->getUri()->withPath(Url::to('/join/' . $code))->withQuery('')->withFragment(''),
            ],
            'scores'   => $this->scoreBoard($course['id']),
            'reports'  => ['reports' => $this->unitReports((int) $course['id'])],
            default    => [],
        };

        return $this->view->render($response, 'courses/show', $data);
    }

    /** @return array<string,mixed> */
    private function requireOwnedCourse(Request $request, int $courseId, int $teacherId): array
    {
        $course = $this->courses->find($courseId);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== $teacherId) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }

        return $course;
    }

    /**
     * แท็บบันทึกหลังสอน: ทุกหน่วยการเรียน (ไม่รวมหน่วยสอบ) ตามลำดับ พร้อมบันทึกหลังสอนของหน่วยนั้น (ยังไม่บันทึก = null)
     *
     * @return list<array{no:int, unit:array<string,mixed>, report:?array<string,mixed>}>
     */
    private function unitReports(int $courseId): array
    {
        $reports = $this->units->reportsForCourse($courseId);
        $out = [];
        foreach ($this->units->forCourse($courseId) as $u) {
            if (!UnitRepository::isExam($u)) {
                $out[] = ['no' => count($out) + 1, 'unit' => $u, 'report' => $reports[(int) $u['id']] ?? null];
            }
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $units @return list<array<string,mixed>> */
    private function decorateUnits(array $units): array
    {
        $no = 0;
        foreach ($units as $i => $u) {
            [$tag, $kind] = match ($u['review_status']) {
                'published' => ['เผยแพร่แล้ว', 'ok'],
                'pending'   => ['รอครูตรวจ', 'warn'],
                default     => ['ฉบับร่าง', 'muted'],
            };
            $units[$i]['tag']      = $tag;
            $units[$i]['tag_kind'] = $kind;

            // หน่วยสอบไม่นับเลขหน่วย และมีแต่แบบทดสอบ
            if (UnitRepository::isExam($u)) {
                $units[$i]['is_exam'] = true;
                $units[$i]['no'] = 'สอบ';
                $units[$i]['meta'] = UnitRepository::KINDS[$u['kind']] . ' · '
                    . ((int) $u['has_posttest'] ? 'มีแบบทดสอบแล้ว' : 'ยังไม่มีแบบทดสอบ');
                continue;
            }

            $units[$i]['is_exam'] = false;
            $units[$i]['no'] = str_pad((string) ++$no, 2, '0', STR_PAD_LEFT);
            $parts = [];
            if ((int) $u['has_pretest'])   $parts[] = 'แบบทดสอบก่อนเรียน';
            if ((int) $u['section_count']) $parts[] = sprintf('เนื้อหา %d ส่วน', $u['section_count']);
            if ((int) $u['has_assignment']) $parts[] = 'ใบงาน';
            if ((int) $u['has_posttest'])  $parts[] = 'แบบทดสอบหลังเรียน';
            $units[$i]['meta'] = $parts ? implode(' · ', $parts) : 'ยังไม่มีเนื้อหา';
        }

        return $units;
    }

    /**
     * แบบทดสอบจัดกลุ่มตามหน่วยการเรียน เรียงตามลำดับหน่วย (ก่อนเรียนขึ้นก่อนหลังเรียน)
     * แบบทดสอบที่ยังไม่ผูกกับหน่วยไปอยู่กลุ่มท้ายสุด
     *
     * @return list<array{no:?string,title:string,is_exam:bool,quizzes:list<array<string,mixed>>}>
     */
    private function quizGroups(int $courseId): array
    {
        $byUnit = [];
        foreach ($this->quizzes->forCourse($courseId) as $q) {
            $byUnit[(int) ($q['unit_id'] ?? 0)][] = $q;
        }

        $groups = [];
        foreach ($this->decorateUnits($this->units->forCourse($courseId)) as $u) {
            if (isset($byUnit[(int) $u['id']])) {
                $groups[] = [
                    'no' => $u['no'],
                    'title' => $u['title'],
                    'is_exam' => $u['is_exam'],
                    'quizzes' => $byUnit[(int) $u['id']],
                ];
                unset($byUnit[(int) $u['id']]);
            }
        }

        // ยังไม่ผูกหน่วย หรือผูกกับหน่วยที่ไม่อยู่ในรายวิชานี้แล้ว
        $loose = array_merge([], ...array_values($byUnit));
        if ($loose) {
            $groups[] = ['no' => null, 'title' => 'ยังไม่ผูกกับหน่วยการเรียน', 'is_exam' => false, 'quizzes' => $loose];
        }

        return $groups;
    }

    /** @return array{scoreQuizzes:list<array<string,mixed>>,scoreRows:list<array<string,mixed>>} */
    private function scoreBoard(int $courseId): array
    {
        $quizzes = $this->db->all(
            "SELECT id, title FROM {quizzes} WHERE course_id = ? AND kind = 'posttest' AND review_status = 'published' AND deleted_at IS NULL ORDER BY created_at",
            [$courseId]
        );
        $students = $this->enrollments->studentsInCourse($courseId);

        $rows = [];
        foreach ($students as $s) {
            $cells = [];
            $total = 0.0;
            foreach ($quizzes as $q) {
                $score = $this->db->value(
                    'SELECT score FROM {quiz_attempts}
                     WHERE quiz_id = ? AND student_id = ? AND status = \'graded\'
                     ORDER BY score DESC LIMIT 1',
                    [$q['id'], $s['id']]
                );
                $cells[] = $score === null ? '—' : (string) (float) $score;
                $total += (float) $score;
            }
            $rows[] = ['name' => $s['full_name'], 'cells' => $cells, 'total' => $total > 0 ? (string) $total : '—'];
        }

        return ['scoreQuizzes' => $quizzes, 'scoreRows' => $rows];
    }
}
