<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\CourseRepository;
use App\Domain\LessonRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Paths;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class LessonController
{
    private const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'txt'];
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly Db $db,
    ) {
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $course = $this->requireOwnedCourse($request, $courseId, (int) $user['id']);

        $lessonId = isset($args['id']) ? (int) $args['id'] : 0;
        $lesson = $lessonId > 0 ? $this->lessons->find($lessonId) : null;

        if ($lessonId > 0 && ($lesson === null || (int) $lesson['course_id'] !== $courseId)) {
            throw new HttpNotFoundException($request, 'ไม่พบบทเรียนนี้');
        }

        $attachments = $lesson
            ? $this->db->all('SELECT * FROM {lesson_attachments} WHERE lesson_id = ? ORDER BY id', [$lessonId])
            : [];

        return $this->view->render($response, 'courses/lesson-edit', [
            'page' => 'courses',
            'course' => $course,
            'lesson' => $lesson,
            'attachments' => $attachments,
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $this->requireOwnedCourse($request, $courseId, (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, "/courses/$courseId");
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            Flash::error('กรุณากรอกชื่อบทเรียน');

            return $this->redirect($response, "/courses/$courseId");
        }

        $fields = [
            'title' => $title,
            'summary' => trim((string) ($data['summary'] ?? '')) ?: null,
            'content' => trim((string) ($data['content'] ?? '')) ?: null,
            'review_status' => ($data['action'] ?? '') === 'publish' ? 'published' : 'draft',
        ];

        $lessonId = (int) ($args['id'] ?? 0);
        if ($lessonId > 0) {
            $lesson = $this->lessons->find($lessonId);
            if ($lesson === null || (int) $lesson['course_id'] !== $courseId) {
                throw new HttpNotFoundException($request, 'ไม่พบบทเรียนนี้');
            }
            if ($fields['review_status'] === 'published' && $lesson['published_at'] === null) {
                $fields['published_at'] = date('Y-m-d H:i:s');
            }
            $this->lessons->update($lessonId, $fields);
            Flash::success('บันทึกบทเรียนแล้ว');
        } else {
            $lessonId = $this->lessons->create($fields + [
                'course_id' => $courseId,
                'sort_order' => $this->lessons->nextSortOrder($courseId),
                'source' => 'manual',
                'created_by' => (int) $user['id'],
                'published_at' => $fields['review_status'] === 'published' ? date('Y-m-d H:i:s') : null,
            ]);
            Flash::success('เพิ่มบทเรียนแล้ว');
        }

        $this->handleUpload($request, $lessonId, (int) $user['id']);

        return $this->redirect($response, "/courses/$courseId/lessons/$lessonId/edit");
    }

    private function handleUpload(Request $request, int $lessonId, int $userId): void
    {
        /** @var array<string,UploadedFileInterface> $files */
        $files = $request->getUploadedFiles();
        $file = $files['attachment'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return;
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            Flash::warning('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่');

            return;
        }

        $original = (string) $file->getClientFilename();
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            Flash::warning('รองรับเฉพาะไฟล์ ' . implode(', ', self::ALLOWED_EXT));

            return;
        }
        if ($file->getSize() > self::MAX_BYTES) {
            Flash::warning('ไฟล์ใหญ่เกิน 20 MB');

            return;
        }

        $dir = Paths::storage('uploads/lessons/' . $lessonId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $stored = bin2hex(random_bytes(8)) . '.' . $ext;
        $file->moveTo($dir . '/' . $stored);

        $this->db->insert('lesson_attachments', [
            'lesson_id' => $lessonId,
            'original_name' => mb_substr($original, 0, 255),
            'stored_path' => 'lessons/' . $lessonId . '/' . $stored,
            'mime_type' => $file->getClientMediaType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $userId,
        ]);

        Flash::success('แนบไฟล์ใบความรู้แล้ว');
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

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
