<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\ReviewRepository;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * หน้ารอตรวจก่อนเผยแพร่ — รวมเนื้อหาที่ผู้ช่วย AI ร่างไว้และยังไม่ได้ยืนยัน จัดกลุ่มตามรายวิชา
 */
final class ReviewController
{
    public function __construct(
        private readonly View $view,
        private readonly ReviewRepository $reviews,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $groups = $this->reviews->pendingGroupedByCourse((int) $user['id']);

        foreach ($groups as $gi => $group) {
            foreach ($group['items'] as $ii => $item) {
                $groups[$gi]['items'][$ii]['ago'] = Thai::ago($item['created_at']);
                $groups[$gi]['items'][$ii]['open_url'] = $this->openUrl($item);
            }
        }

        return $this->view->render($response, 'review/index', [
            'page' => 'review',
            'groups' => $groups,
        ]);
    }

    private function openUrl(array $item): ?string
    {
        if ($item['course_id'] === null || $item['target_id'] === null) {
            return null;
        }

        return match ($item['target_type']) {
            'quiz' => Url::to("/courses/{$item['course_id']}/quizzes/{$item['target_id']}/review"),
            'lesson_plan' => Url::to("/courses/{$item['course_id']}/lesson-plan/{$item['target_id']}"),
            'lesson' => Url::to("/courses/{$item['course_id']}/units/{$item['target_id']}/edit"),
            default => null,
        };
    }
}
