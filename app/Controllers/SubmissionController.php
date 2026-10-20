<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\SubmissionRepository;
use App\Domain\UnitRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Paths;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;
use Throwable;

/**
 * การส่งงาน (ใบงานของหน่วย / งานที่มอบหมาย) — นักเรียนพิมพ์ข้อความ แนบรูปภาพหรือ PDF · ครูเปิดดู ให้คะแนน หรือส่งกลับให้แก้
 *
 * นักเรียน
 *   GET  /learn/task/{id}                     หน้างานที่มอบหมาย (ใบงานใช้หน้า /learn/units/{u}/worksheet/{id} เดิม)
 *   POST /learn/assignments/{id}/submit       ส่ง / แก้ไขงาน (multipart: content, files[], remove[])
 *   GET  /learn/submissions/files/{fileId}    เปิดไฟล์ของตัวเอง
 * ครู (เจ้าของรายวิชา)
 *   GET  /courses/{c}/preview/task/{id}                       มุมมองนักเรียนของงานที่มอบหมาย
 *   GET  /courses/{c}/assignments/{id}/submissions            รายชื่อนักเรียน + งานที่ส่ง
 *   POST /courses/{c}/assignments/{id}/submissions/{sid}      ให้คะแนน (do=grade) / ส่งกลับให้แก้ (do=return)
 *   GET  /courses/{c}/submissions/files/{fileId}              เปิดไฟล์ที่นักเรียนส่ง
 */
final class SubmissionController
{
    public function __construct(
        private readonly View $view,
        private readonly Db $db,
        private readonly Auth $auth,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly EnrollmentRepository $enrollments,
        private readonly SubmissionRepository $submissions,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── นักเรียน ─────────────────────────────────────────────────────────────

    public function task(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $assignment = $this->studentAssignment($request, (int) $args['id'], (int) $user['id']);
        if ($assignment['kind'] !== 'task') {
            throw new HttpNotFoundException($request, 'ไม่พบงานนี้');
        }
        $course = $this->courses->find((int) $assignment['course_id']);

        return $this->renderTask($response, $course, $assignment, (int) $user['id'], false);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $studentId = (int) $user['id'];
        $assignment = $this->studentAssignment($request, (int) $args['id'], $studentId);
        $back = $response->withHeader('Location', Url::to(self::studentUrl($assignment)))->withStatus(302);

        $data = (array) $request->getParsedBody();
        if ($data === [] && (int) $request->getHeaderLine('Content-Length') > 0) {
            // ใหญ่เกิน post_max_size — PHP ทิ้งทั้งฟอร์มรวมถึง _token จึงไม่ใช่เรื่องเซสชันหมดอายุ
            Flash::error('ไฟล์รวมกันใหญ่เกินที่เซิร์ฟเวอร์รับได้ กรุณาลดจำนวนหรือขนาดไฟล์แล้วส่งใหม่');

            return $back;
        }
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่');

            return $back;
        }

        $current = $this->submissions->forStudent((int) $assignment['id'], $studentId);
        if (!SubmissionRepository::editable($assignment, $current)) {
            Flash::error($current !== null && $current['status'] === 'graded'
                ? 'ครูตรวจงานนี้แล้ว แก้ไขไม่ได้'
                : 'เลยกำหนดส่งแล้ว งานนี้ไม่รับส่งช้า');

            return $back;
        }

        $content = trim(str_replace("\r\n", "\n", (string) ($data['content'] ?? '')));
        // ส่งไม่ผ่านแล้วข้อความที่พิมพ์ไว้ต้องไม่หาย — เก็บไว้เติมกลับในฟอร์ม (formContext ดึงออกครั้งเดียว)
        $_SESSION['sub_draft'][(int) $assignment['id']] = mb_substr($content, 0, SubmissionRepository::MAX_TEXT);
        if (mb_strlen($content) > SubmissionRepository::MAX_TEXT) {
            Flash::error('ข้อความยาวเกิน ' . number_format(SubmissionRepository::MAX_TEXT) . ' ตัวอักษร');

            return $back;
        }

        // ตรวจไฟล์ทั้งหมดก่อนบันทึกอะไร — ผิดไฟล์เดียวก็ไม่บันทึกทั้งชุด
        $remove = array_map('intval', (array) ($data['remove'] ?? []));
        $keep = array_filter($current['files'] ?? [], static fn (array $f): bool => !in_array((int) $f['id'], $remove, true));
        $uploads = [];
        $posted = $request->getUploadedFiles()['files'] ?? [];
        foreach (is_array($posted) ? $posted : [$posted] as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = (string) $file->getClientFilename();
            $error = match (true) {
                in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true),
                (int) $file->getSize() > SubmissionRepository::MAX_BYTES => 'ใหญ่เกิน 10 MB',
                $file->getError() !== UPLOAD_ERR_OK => 'อัปโหลดไม่สำเร็จ',
                default => null,
            };
            $mime = null;
            if ($error === null) {
                $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getStream()->getMetadata('uri'));
                if (!isset(SubmissionRepository::TYPES[$mime])) {
                    $error = 'รับเฉพาะรูปภาพ (JPG, PNG, WEBP, GIF) หรือ PDF';
                }
            }
            if ($error !== null) {
                Flash::error('ไฟล์ "' . mb_substr($name, 0, 60) . '" ' . $error);

                return $back;
            }
            $uploads[] = [$file, $name, $mime];
        }
        if (count($keep) + count($uploads) > SubmissionRepository::MAX_FILES) {
            Flash::error('แนบไฟล์ได้ไม่เกิน ' . SubmissionRepository::MAX_FILES . ' ไฟล์ต่องาน');

            return $back;
        }
        if ($content === '' && $keep === [] && $uploads === []) {
            Flash::error('กรุณาพิมพ์คำตอบ หรือแนบไฟล์อย่างน้อยหนึ่งไฟล์');

            return $back;
        }

        $dir = 'submissions/' . (int) $assignment['id'];
        if (!is_dir(Paths::storage('uploads/' . $dir))) {
            @mkdir(Paths::storage('uploads/' . $dir), 0775, true);
        }
        try {
            $id = $this->submissions->save((int) $assignment['id'], $studentId, $content);
            $this->submissions->removeFiles($id, $remove);
            foreach ($uploads as [$file, $name, $mime]) {
                $stored = $dir . '/' . $studentId . '-' . bin2hex(random_bytes(8)) . '.' . SubmissionRepository::TYPES[$mime];
                $file->moveTo(Paths::storage('uploads/' . $stored));
                $this->submissions->addFile($id, [
                    'original_name' => mb_substr($name !== '' ? $name : basename($stored), 0, 255),
                    'stored_path'   => $stored,
                    'mime_type'     => $mime,
                    'size_bytes'    => (int) $file->getSize(),
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Submission save failed: ' . $e->getMessage(), ['assignment' => $assignment['id'], 'exception' => $e]);
            Flash::error('บันทึกงานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');

            return $back;
        }

        unset($_SESSION['sub_draft'][(int) $assignment['id']]);
        $this->auth->log('submission.submit', 'assignment:' . $assignment['id'], [
            'files' => count($keep) + count($uploads),
            'chars' => mb_strlen($content),
            'resubmit' => $current !== null,
        ]);
        Flash::success($current === null ? 'ส่งงานเรียบร้อยแล้ว' : 'บันทึกการแก้ไขและส่งงานใหม่แล้ว');

        return $back;
    }

    public function studentFile(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $file = $this->submissions->file((int) $args['fileId']);
        if ($file === null || (int) $file['student_id'] !== (int) $user['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบไฟล์นี้');
        }

        return $this->send($request, $response, $file);
    }

    // ── ครู ──────────────────────────────────────────────────────────────────

    public function previewTask(Request $request, Response $response, array $args): Response
    {
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $assignment = $this->db->first(
            "SELECT * FROM {assignments} WHERE id = ? AND course_id = ? AND kind = 'task'",
            [(int) $args['id'], (int) $course['id']]
        ) ?? throw new HttpNotFoundException($request, 'ไม่พบงานนี้');

        return $this->renderTask($response, $course, $assignment, 0, true);
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $assignment = $this->courseAssignment($request, (int) $args['id'], (int) $course['id']);
        $roster = $this->submissions->roster((int) $assignment['id'], (int) $course['id']);
        $sent = array_values(array_filter($roster, static fn (array $r): bool => $r['id'] !== null));

        return $this->view->render($response, 'courses/submissions', [
            'page'       => 'courses',
            'course'     => $course,
            'assignment' => $assignment,
            'unit'       => $assignment['unit_id'] ? $this->units->find((int) $assignment['unit_id']) : null,
            'roster'     => $roster,
            'counts'     => [
                'students' => count($roster),
                'sent'     => count($sent),
                'graded'   => count(array_filter($sent, static fn (array $r): bool => $r['status'] === 'graded')),
                'waiting'  => count(array_filter($sent, static fn (array $r): bool => $r['status'] === 'submitted')),
            ],
            'statusLabels' => SubmissionRepository::STATUS,
            'open'       => (int) ($request->getQueryParams()['open'] ?? 0),
        ]);
    }

    public function grade(Request $request, Response $response, array $args): Response
    {
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $assignment = $this->courseAssignment($request, (int) $args['id'], (int) $course['id']);
        $submission = $this->submissions->find((int) $args['sid']);
        if ($submission === null || (int) $submission['assignment_id'] !== (int) $assignment['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบงานที่ส่งนี้');
        }
        $url = sprintf('/courses/%d/assignments/%d/submissions', $course['id'], $assignment['id']);
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่');

            return $response->withHeader('Location', Url::to($url . '?open=' . $submission['id']))->withStatus(302);
        }

        $feedback = trim(mb_substr((string) ($data['feedback'] ?? ''), 0, 5000));
        $raw = trim((string) ($data['score'] ?? ''));
        $max = (float) $assignment['max_score'];
        if (($data['do'] ?? '') === 'return') {
            if ($feedback === '') {
                Flash::error('กรุณาเขียนความเห็นบอกนักเรียนว่าต้องแก้ไขอะไร ก่อนส่งกลับ');

                return $response->withHeader('Location', Url::to($url . '?open=' . $submission['id']))->withStatus(302);
            }
            $this->submissions->grade((int) $submission['id'], null, $feedback, 'returned', (int) $request->getAttribute('user')['id']);
            Flash::success('ส่งงานกลับให้นักเรียนแก้ไขแล้ว');
        } else {
            if ($raw === '' || !is_numeric($raw) || (float) $raw < 0 || (float) $raw > $max) {
                Flash::error(sprintf('คะแนนต้องอยู่ระหว่าง 0 – %s', rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.')));

                return $response->withHeader('Location', Url::to($url . '?open=' . $submission['id']))->withStatus(302);
            }
            $this->submissions->grade((int) $submission['id'], round((float) $raw, 2), $feedback, 'graded', (int) $request->getAttribute('user')['id']);
            Flash::success('บันทึกคะแนนแล้ว');
        }
        $this->auth->log('submission.' . (($data['do'] ?? '') === 'return' ? 'return' : 'grade'), 'submission:' . $submission['id']);

        return $response->withHeader('Location', Url::to($url . '#s' . $submission['id']))->withStatus(302);
    }

    public function teacherFile(Request $request, Response $response, array $args): Response
    {
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $file = $this->submissions->file((int) $args['fileId']);
        if ($file === null || (int) $file['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบไฟล์นี้');
        }

        return $this->send($request, $response, $file);
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /** ลิงก์หน้างานของนักเรียน — ใบงานอยู่ในเส้นทางของหน่วย งานที่มอบหมายมีหน้าของตัวเอง */
    public static function studentUrl(array $assignment): string
    {
        return $assignment['kind'] === 'worksheet' && $assignment['unit_id']
            ? '/learn/units/' . $assignment['unit_id'] . '/worksheet/' . $assignment['id']
            : '/learn/task/' . $assignment['id'];
    }

    /** @return array<string,mixed> งานที่นักเรียนคนนี้เห็นได้: ลงทะเบียนวิชาแล้ว และหน่วยของงาน (ถ้ามี) เผยแพร่แล้ว */
    private function studentAssignment(Request $request, int $id, int $studentId): array
    {
        $a = $this->db->first(
            "SELECT a.*, u.review_status AS unit_status, u.title AS unit_title
             FROM {assignments} a LEFT JOIN {units} u ON u.id = a.unit_id
             WHERE a.id = ?",
            [$id]
        );
        if ($a === null || ($a['unit_id'] !== null && $a['unit_status'] !== 'published')
            || ($a['kind'] === 'worksheet' && $a['unit_id'] === null)) {
            throw new HttpNotFoundException($request, 'ไม่พบงานนี้');
        }
        if (!$this->enrollments->isEnrolled((int) $a['course_id'], $studentId)) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้ลงทะเบียนในรายวิชานี้');
        }

        return $a;
    }

    /** @return array<string,mixed> */
    private function ownedCourse(Request $request, int $courseId): array
    {
        $course = $this->courses->find($courseId);
        if ($course === null || (int) $course['teacher_id'] !== (int) $request->getAttribute('user')['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }

        return $course;
    }

    /** @return array<string,mixed> */
    private function courseAssignment(Request $request, int $id, int $courseId): array
    {
        return $this->db->first('SELECT * FROM {assignments} WHERE id = ? AND course_id = ?', [$id, $courseId])
            ?? throw new HttpNotFoundException($request, 'ไม่พบงานนี้');
    }

    private function renderTask(Response $response, array $course, array $assignment, int $studentId, bool $preview): Response
    {
        $submission = $preview ? null : $this->submissions->forStudent((int) $assignment['id'], $studentId);

        return $this->view->render($response, 'learn/task', [
            'page'       => $preview ? 'courses' : 'learn',
            'preview'    => $preview,
            'course'     => $course,
            'assignment' => $assignment,
            'unit'       => $assignment['unit_id'] ? $this->units->find((int) $assignment['unit_id']) : null,
            'backUrl'    => $preview ? '/courses/' . $course['id'] . '/preview' : '/learn/' . $course['id'],
        ] + self::formContext($assignment, $submission, $preview));
    }

    /**
     * ตัวแปรของฟอร์มส่งงาน (_partials/submission.latte) — ใช้ร่วมกับหน้าใบงานใน StudentController
     *
     * @return array<string,mixed>
     */
    public static function formContext(array $assignment, ?array $submission, bool $preview): array
    {
        $draft = $_SESSION['sub_draft'][(int) $assignment['id']] ?? null;
        unset($_SESSION['sub_draft'][(int) $assignment['id']]);

        return [
            'submission' => $submission,
            'subText'    => $draft ?? (string) ($submission['content'] ?? ''),
            'subEditable' => !$preview && SubmissionRepository::editable($assignment, $submission),
            'subClosed'  => SubmissionRepository::closed($assignment),
            'subLate'    => SubmissionRepository::late($assignment, $submission),
            'subStatus'  => SubmissionRepository::STATUS,
            'subLimits'  => [
                'files' => SubmissionRepository::MAX_FILES,
                'mb'    => (int) (SubmissionRepository::MAX_BYTES / 1048576),
                'text'  => SubmissionRepository::MAX_TEXT,
            ],
        ];
    }

    private function send(Request $request, Response $response, array $file): Response
    {
        $path = SubmissionRepository::path((string) $file['stored_path']);
        $mime = (string) $file['mime_type'];
        if ($path === null || !isset(SubmissionRepository::TYPES[$mime])) {
            throw new HttpNotFoundException($request, 'ไม่พบไฟล์นี้');
        }
        $name = (string) $file['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';

        return $response
            ->withBody(new Stream(fopen($path, 'rb')))
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', sprintf('inline; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name)))
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
