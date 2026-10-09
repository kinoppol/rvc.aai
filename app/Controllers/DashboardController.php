<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\ReviewRepository;
use App\Domain\SettingsRepository;
use App\Install\Installer;
use App\Migration\Migrator;
use App\Support\Database;
use App\Support\Db;
use App\Support\Thai;
use App\Support\View;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly Db $db,
        private readonly CourseRepository $courses,
        private readonly EnrollmentRepository $enrollments,
        private readonly ReviewRepository $reviews,
        private readonly SettingsRepository $settings,
        private readonly Migrator $migrator,
        private readonly PDO $pdo,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        /** @var array<string,mixed> $user */
        $user = $request->getAttribute('user');

        // ผู้ดูแลครูมีรายวิชาของตัวเองเหมือนครูทั่วไป จึงเห็นหน้าแรกแบบเดียวกัน
        // ส่วนงานกำกับดูแลอยู่ในเมนูด้านข้าง ไม่ใช่ภาพรวมเครื่อง AI ของผู้ดูแลระบบ
        return match ($user['role']) {
            'teacher', 'supervisor' => $this->teacher($response, $user),
            'student' => $this->student($response, $user),
            default => $this->admin($response, $user),
        };
    }

    private function teacher(Response $response, array $user): Response
    {
        $teacherId = (int) $user['id'];
        $courses = $this->courses->forTeacher($teacherId);

        $period = sprintf('%04d-%02d', $this->settings->int('academic_year'), (int) date('n'));
        $quota = $this->db->first(
            'SELECT monthly_limit, used_count FROM {ai_quotas} WHERE user_id = ? AND period = ?',
            [$teacherId, $period]
        ) ?? ['monthly_limit' => $this->settings->int('ai_monthly_quota', 60), 'used_count' => 0];

        $limit = max(1, (int) $quota['monthly_limit']);
        $used = (int) $quota['used_count'];

        $recent = array_map(static function (array $r): array {
            $r['time'] = Thai::ago($r['created_at']);

            return $r;
        }, $this->reviews->recentForTeacher($teacherId, 6));

        return $this->view->render($response, 'dashboard/teacher', [
            'page' => 'dashboard',
            'firstName' => $this->firstName($user['full_name']),
            'courses' => $courses,
            'todayClasses' => min(3, count($courses)),
            'quota' => ['limit' => $limit, 'used' => $used, 'left' => max(0, $limit - $used), 'pct' => (int) round(min(1, $used / $limit) * 100)],
            'recent' => $recent,
        ]);
    }

    private function student(Response $response, array $user): Response
    {
        $courses = $this->enrollments->coursesForStudent((int) $user['id']);

        return $this->view->render($response, 'dashboard/student', [
            'page' => 'dashboard',
            'firstName' => $this->firstName($user['full_name']),
            'courses' => $courses,
        ]);
    }

    private function admin(Response $response, array $user): Response
    {
        $status = $this->migrator->status();
        $prefix = $this->db->prefix();

        $counts = $this->pdo->query(sprintf(
            'SELECT role, COUNT(*) AS total FROM `%susers` GROUP BY role',
            $prefix
        ))->fetchAll();

        $lock = Installer::readLock();

        return $this->view->render($response, 'dashboard/admin', [
            'page' => 'dashboard',
            'server' => Database::inspectServer($this->pdo),
            'phpVersion' => PHP_VERSION,
            'systemVersion' => Installer::VERSION,
            'userCounts' => array_column($counts, 'total', 'role'),
            'migrationSummary' => [
                'ran' => count($status['ran']),
                'pending' => count($status['pending']),
                'missing' => count($status['missing']),
            ],
            'demoSeeded' => $this->settings->bool('demo_seeded'),
            'installedAt' => $lock['installed_at'] ?? null,
        ]);
    }

    private function firstName(string $fullName): string
    {
        $name = preg_replace('/^(อ\.|นาย|นาง|นางสาว|น\.ส\.)\s*/u', '', $fullName) ?? $fullName;
        $parts = preg_split('/\s+/u', trim($name)) ?: [$name];

        return $parts[0];
    }
}
