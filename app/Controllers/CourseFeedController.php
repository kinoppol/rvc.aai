<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\CourseFeedRepository;
use App\Domain\CourseRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * ครูประกาศข่าวสารของรายวิชา (แท็บประกาศในหน้ารายวิชา) — นักเรียนทุกคนในรายวิชาเห็นที่หน้า /learn/{id}
 * เพิ่ม แก้ไข ปักหมุด และลบประกาศ · ทุกคำสั่งกลับไปที่ ?tab=feed
 */
final class CourseFeedController
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseFeedRepository $feed,
        private readonly Auth $auth,
    ) {
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        [$course, $data, $back] = $this->prepare($request, $response, (int) $args['id']);
        if ($course === null) {
            return $back;
        }

        $body = $this->body($data);
        if ($body === null) {
            return $back;
        }

        $id = $this->feed->create((int) $course['id'], (int) $request->getAttribute('user')['id'], $body, !empty($data['pinned']));
        $this->auth->log('course.post', 'course#' . $course['id'], ['post' => $id]);
        Flash::success('ประกาศแล้ว · นักเรียน ' . (int) $course['student_count'] . ' คนในรายวิชาจะเห็นประกาศนี้ที่หน้ารายวิชา');

        return $back;
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        [$course, $data, $back] = $this->prepare($request, $response, (int) $args['id']);
        if ($course === null) {
            return $back;
        }
        $post = $this->ownPost($request, $course, (int) $args['postId']);

        $body = $this->body($data);
        if ($body === null) {
            return $back;
        }

        $this->feed->update((int) $post['id'], $body);
        Flash::success('แก้ไขประกาศแล้ว');

        return $back;
    }

    public function pin(Request $request, Response $response, array $args): Response
    {
        [$course, $data, $back] = $this->prepare($request, $response, (int) $args['id']);
        if ($course === null) {
            return $back;
        }
        $post = $this->ownPost($request, $course, (int) $args['postId']);

        $pinned = ($data['pinned'] ?? '') === '1';
        $this->feed->setPinned((int) $post['id'], $pinned);
        Flash::success($pinned ? 'ปักหมุดประกาศไว้บนสุดแล้ว' : 'เลิกปักหมุดประกาศแล้ว');

        return $back;
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        [$course, , $back] = $this->prepare($request, $response, (int) $args['id']);
        if ($course === null) {
            return $back;
        }
        $post = $this->ownPost($request, $course, (int) $args['postId']);

        $this->feed->delete((int) $post['id']);
        $this->auth->log('course.post_delete', 'course#' . $course['id'], ['post' => (int) $post['id']]);
        Flash::success('ลบประกาศแล้ว');

        return $back;
    }

    /**
     * ตรวจ CSRF + ความเป็นเจ้าของรายวิชา · รายวิชา null = ให้ส่ง $back กลับทันที (ข้อความแจ้งเตือนตั้งไว้แล้ว)
     *
     * @return array{0:?array<string,mixed>,1:array<string,mixed>,2:Response}
     */
    private function prepare(Request $request, Response $response, int $courseId): array
    {
        $data = (array) $request->getParsedBody();
        $back = $response->withHeader('Location', Url::to('/courses/' . $courseId . '?tab=feed'))->withStatus(302);

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return [null, $data, $back];
        }
        if (!$this->feed->ready()) {
            Flash::error('ระบบประกาศยังไม่พร้อม — ผู้ดูแลระบบต้องปรับฐานข้อมูลที่หน้าฐานข้อมูลก่อน');

            return [null, $data, $back];
        }

        $course = $this->courses->find($courseId);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== (int) $request->getAttribute('user')['id']) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }

        return [$course, $data, $back];
    }

    /** @param array<string,mixed> $course @return array<string,mixed> */
    private function ownPost(Request $request, array $course, int $postId): array
    {
        $post = $this->feed->find($postId);
        if ($post === null || (int) $post['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบประกาศนี้');
        }

        return $post;
    }

    /** @param array<string,mixed> $data */
    private function body(array $data): ?string
    {
        $body = trim(str_replace("\r\n", "\n", (string) ($data['body'] ?? '')));
        if ($body === '') {
            Flash::error('กรุณาพิมพ์ข้อความที่จะประกาศ');

            return null;
        }
        if (mb_strlen($body) > CourseFeedRepository::MAX_BODY) {
            Flash::error('ประกาศยาวเกิน ' . number_format(CourseFeedRepository::MAX_BODY) . ' ตัวอักษร');

            return null;
        }

        return $body;
    }
}
