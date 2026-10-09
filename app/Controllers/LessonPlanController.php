<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiRouter;
use App\AI\AiUnavailableException;
use App\AI\LessonPlanGenerator;
use App\Auth\Auth;
use App\Controllers\Concerns\OwnsCourse;
use App\Domain\AiRepository;
use App\Domain\CourseRepository;
use App\Domain\LessonPlanRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * ทำแผนการจัดการเรียนรู้ด้วยผู้ช่วย AI: กรอกข้อมูลหน่วย → ร่างเอกสาร → แก้ในหน้าเดิม → ยืนยัน/ส่งออก
 */
final class LessonPlanController
{
    use OwnsCourse;

    private const STYLES = ['ปฏิบัติในโรงฝึกงาน', 'บรรยายและสาธิต', 'เรียนรู้จากปัญหา'];

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly LessonPlanRepository $plans,
        private readonly AiRepository $ai,
        private readonly AiRouter $router,
        private readonly LessonPlanGenerator $generator,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
    ) {
    }

    protected function courseRepo(): CourseRepository
    {
        return $this->courses;
    }

    public function form(Request $request, Response $response, array $args): Response
    {
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $route = $this->safeChip((int) $request->getAttribute('user')['id']);

        return $this->view->render($response, 'plan/form', [
            'page' => 'courses',
            'course' => $course,
            'styles' => self::STYLES,
            'aiChip' => $route,
            'existing' => $this->plans->forCourse($course['id'], (int) $request->getAttribute('user')['id']),
        ]);
    }

    public function stream(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $q = $request->getQueryParams();

        $params = [
            'unit' => trim((string) ($q['unit'] ?? '')) ?: 'หน่วยการเรียนรู้',
            'competency' => trim((string) ($q['competency'] ?? '')),
            'hours' => preg_replace('/[^0-9\-–]/u', '', (string) ($q['hours'] ?? '6')) ?: '6',
            'week' => trim((string) ($q['week'] ?? '')),
            'style' => in_array($q['style'] ?? '', self::STYLES, true) ? (string) $q['style'] : self::STYLES[0],
        ];

        ignore_user_abort(true);
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $send = static function (string $event, array $data): void {
            echo "event: $event\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };

        try {
            $quality = $this->settings->userQualityPref((int) $user['id']);
            $route = $this->router->route((int) $user['id'], $quality, real: true);
        } catch (AiUnavailableException $e) {
            $send('error', ['reason' => $e->reason, 'message' => $e->getMessage()]);

            return $response;
        }

        $send('meta', ['chip' => $route->chip]);

        $jobId = $this->ai->createJob([
            'user_id' => (int) $user['id'],
            'course_id' => $course['id'],
            'kind' => 'lesson_plan',
            'source' => $route->source,
            'status' => 'running',
            'prompt' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        $sections = [];
        try {
            foreach ($this->generator->stream($route, $params, $quality) as $ev) {
                if ($ev['event'] === 'section') {
                    $sections[] = $ev['data'];
                    $send('section', $ev['data']);
                } elseif ($ev['event'] === 'error') {
                    $send('error', ['reason' => 'generate_failed', 'message' => $ev['message']]);
                    $this->ai->updateJob($jobId, ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s')]);

                    return $response;
                }
            }
        } catch (Throwable $e) {
            $send('error', ['reason' => 'generate_failed', 'message' => 'ระบบขัดข้องระหว่างร่างเอกสาร']);
            $this->ai->updateJob($jobId, ['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => date('Y-m-d H:i:s')]);

            return $response;
        }

        if ($sections === []) {
            $send('error', ['reason' => 'empty', 'message' => 'ผู้ช่วยไม่ได้ร่างหัวข้อออกมา กรุณาลองใหม่']);
            $this->ai->updateJob($jobId, ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s')]);

            return $response;
        }

        $title = sprintf('แผนการจัดการเรียนรู้ · %s', $params['unit']);
        $doc = [
            'title' => $title,
            'meta' => $params + ['course_code' => $course['code'], 'course_name' => $course['name']],
            'sections' => $sections,
        ];

        $planId = $this->plans->create((int) $user['id'], $jobId, $course['id'], $doc);
        $this->ai->updateJob($jobId, ['status' => 'done', 'finished_at' => date('Y-m-d H:i:s')]);

        if ($route->countsQuota) {
            $this->ai->consumeQuota((int) $user['id'], $this->period(), $this->settings->int('ai_monthly_quota', 60));
        }
        $this->ai->logUsage((int) $user['id'], $jobId, $route->source, $route->model, true);
        $this->auth->log('ai.lesson_plan.generate', 'plan#' . $planId);

        $send('done', ['editUrl' => Url::to("/courses/{$course['id']}/lesson-plan/$planId")]);

        return $response;
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $plan = $this->requirePlan($request, (int) $args['id'], (int) $user['id']);

        return $this->view->render($response, 'plan/edit', [
            'page' => 'courses',
            'course' => $course,
            'plan' => $plan,
            'doc' => $plan['doc'],
            'createdAgo' => Thai::ago($plan['created_at']),
            'published' => $plan['review_status'] === 'approved',
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $plan = $this->requirePlan($request, (int) $args['id'], (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, "/courses/{$course['id']}/lesson-plan/{$plan['id']}");
        }

        $doc = $plan['doc'];
        $incoming = json_decode((string) ($data['sections'] ?? '[]'), true);
        if (is_array($incoming)) {
            foreach ($doc['sections'] as $i => $s) {
                if (isset($incoming[$i]['body'])) {
                    $doc['sections'][$i]['body'] = (string) $incoming[$i]['body'];
                }
            }
        }
        $doc['title'] = trim((string) ($data['title'] ?? '')) ?: $doc['title'];

        $this->plans->updateDoc((int) $plan['id'], $doc);

        $publish = ($data['action'] ?? '') === 'publish';
        $this->plans->setStatus((int) $plan['id'], $publish ? 'approved' : 'pending', (int) $user['id']);
        $this->auth->log($publish ? 'lesson_plan.publish' : 'lesson_plan.draft', 'plan#' . $plan['id']);

        if ($publish) {
            Flash::success('บันทึกแผนการสอนแล้ว');

            return $this->redirect($response, "/courses/{$course['id']}");
        }

        Flash::success('เก็บไว้ในรายการรอตรวจแล้ว');

        return $this->redirect($response, '/review');
    }

    /** ส่งออกเป็นไฟล์เอกสาร Word (HTML ที่ Word เปิดได้) หรือหน้าพร้อมพิมพ์เป็น PDF */
    public function export(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $this->ownedCourse($request, (int) $args['courseId']);
        $plan = $this->requirePlan($request, (int) $args['id'], (int) $user['id']);
        $format = (string) ($args['format'] ?? 'doc');

        $html = $this->view->renderToString('plan/document', [
            'doc' => $plan['doc'],
            'teacher' => $user['full_name'],
            'today' => Thai::date('now'),
            'forPrint' => $format === 'pdf',
        ]);

        $this->auth->log('lesson_plan.export', 'plan#' . $plan['id'], ['format' => $format]);

        if ($format === 'pdf') {
            $response->getBody()->write($html);

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $filename = 'แผนการสอน-' . preg_replace('/\s+/u', '_', (string) ($plan['doc']['meta']['unit'] ?? 'unit')) . '.doc';
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'application/msword; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode($filename));
    }

    // ---- ภายใน ----

    /** @return array<string,mixed> */
    private function requirePlan(Request $request, int $id, int $userId): array
    {
        $plan = $this->plans->find($id, $userId);
        if ($plan === null) {
            throw new HttpNotFoundException($request, 'ไม่พบแผนการสอนนี้');
        }

        return $plan;
    }

    private function safeChip(int $userId): string
    {
        try {
            return $this->router->route($userId, $this->settings->userQualityPref($userId), real: true)->chip;
        } catch (AiUnavailableException) {
            return 'ผู้ช่วย AI ยังใช้งานไม่ได้';
        }
    }

    private function period(): string
    {
        return sprintf('%04d-%02d', $this->settings->int('academic_year', (int) date('Y')), (int) date('n'));
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
