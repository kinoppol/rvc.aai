<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\CourseRepository;
use App\Support\Cover;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * ส่งรูปหน้าปกรายวิชา (เก็บไว้ใน storage นอก public)
 * ผู้ที่ล็อกอินแล้วดูได้ทุกวิชา · ผู้มาเยือนดูได้เฉพาะวิชาที่ครูเปิดให้แสดงในหน้าแรกสาธารณะ
 */
final class CourseCoverController
{
    private const TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly Auth $auth,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $course = $this->courses->find((int) $args['id']);
        $file = (string) ($course['cover_image'] ?? '');

        if ($course === null || $file === '' || basename($file) !== $file
            || ($this->auth->user() === null && (int) ($course['show_on_landing'] ?? 0) !== 1)) {
            throw new HttpNotFoundException($request);
        }

        $path = Cover::dir() . '/' . $file;
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        if ($type === null || !is_file($path)) {
            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write((string) file_get_contents($path));

        // ที่อยู่รูปมีเลขเวอร์ชัน (?v=) เปลี่ยนตามไฟล์ — แคชได้นาน
        return $response
            ->withHeader('Content-Type', $type)
            ->withHeader('Cache-Control', 'private, max-age=604800')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
