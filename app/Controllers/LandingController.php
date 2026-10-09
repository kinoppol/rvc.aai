<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\CourseRepository;
use App\Domain\SettingsRepository;
use App\Support\Config;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * หน้าแรกสาธารณะ — แสดงรายวิชาที่ครูเลือกเปิดเผย ให้ผู้สนใจภายนอกดูได้โดยไม่ต้องเข้าสู่ระบบ
 *
 * สาขาวิชาการตลาด/ธุรกิจค้าปลีกเป็นสาขานำร่องของระบบผู้ช่วย AI จึงชูขึ้นเป็นจุดขายหลักของหน้านี้
 * (ป้าย "โครงการนำร่อง" และข้อความในส่วน hero) — เมื่อมีรายวิชาของสาขานั้นเปิดเผยจริง ระบบจะจัดกลุ่มของ
 * สาขานั้นไว้บนสุดให้อัตโนมัติ ไม่กระทบสาขาอื่นที่แสดงตามปกติ
 */
final class LandingController
{
    /** คำที่ใช้จับกลุ่มสาขาวิชานำร่อง (การตลาด/ธุรกิจค้าปลีก) แบบไม่สนตัวพิมพ์ใหญ่เล็ก */
    private const PILOT_PATTERN = '~ค้าปลีก|การตลาด|retail|marketing~ui';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly CourseRepository $courses,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', Url::to('/dashboard'))->withStatus(302);
        }

        $groups = $this->courses->publicLanding();
        $pilotDept = $this->findPilotDepartment($groups);
        $groups = $this->pilotFirst($groups, $pilotDept);
        $courseCount = array_sum(array_map(static fn (array $g): int => count($g['courses']), $groups));

        return $this->view->render($response, 'landing', [
            'groups' => $groups,
            'courseCount' => $courseCount,
            'pilotDept' => $pilotDept,
            'college' => (string) $this->settings->get('college_name') ?: $this->config->get('app.college'),
            'appName' => (string) $this->settings->get('site_name') ?: $this->config->get('app.name'),
            'term' => $this->termLabel(),
        ]);
    }

    /** @param list<array{department:string,courses:list<array<string,mixed>>}> $groups */
    private function findPilotDepartment(array $groups): ?string
    {
        foreach ($groups as $g) {
            if (preg_match(self::PILOT_PATTERN, (string) $g['department'])) {
                return (string) $g['department'];
            }
        }

        return null;
    }

    /**
     * ดันกลุ่มของสาขานำร่องขึ้นบนสุด เพื่อให้เป็นสิ่งแรกที่ผู้เข้าชมเห็น
     *
     * @param list<array{department:string,courses:list<array<string,mixed>>}> $groups
     * @return list<array{department:string,courses:list<array<string,mixed>>}>
     */
    private function pilotFirst(array $groups, ?string $pilotDept): array
    {
        if ($pilotDept === null) {
            return $groups;
        }

        usort($groups, static fn (array $a, array $b): int => ($b['department'] === $pilotDept) <=> ($a['department'] === $pilotDept));

        return $groups;
    }

    private function termLabel(): string
    {
        $year = $this->settings->int('academic_year');
        $semester = $this->settings->int('semester');

        return $year > 0 ? sprintf('ภาคเรียนที่ %d / %d', $semester, $year) : '';
    }
}
