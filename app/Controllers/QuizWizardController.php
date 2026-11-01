<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiRoute;
use App\AI\AiRouter;
use App\AI\AiUnavailableException;
use App\AI\ModelBudget;
use App\AI\QuizGenerator;
use App\AI\UnitKnowledge;
use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\AttemptRepository;
use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Domain\QuizRepository;
use App\Domain\ReviewRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * ตัวช่วยออกข้อสอบจากบทเรียน: ตั้งค่างาน → สตรีมร่างทีละข้อ → ตรวจและแก้ในหน้าเดิม → ยืนยัน
 */
final class QuizWizardController
{
    private const COUNT_OPTIONS = [5, 8, 10, 20];
    private const TYPE_OPTIONS = ['ปรนัย', 'อัตนัย', 'จับคู่'];
    private const LEVELS = [
        'ง่าย' => 'เน้นความจำและคำศัพท์พื้นฐาน',
        'กลาง' => 'ความเข้าใจและการนำไปใช้ในงานจริง',
        'ยาก' => 'วิเคราะห์อาการเสียและแก้ปัญหา',
    ];
    /**
     * เนื้อหายาวเกินที่โมเดลรับไหวในรอบเดียว (ModelBudget::chars) → แบ่งออกข้อสอบเป็นหลายรอบ ประมาณไม่เกินจำนวนนี้ (และไม่เกินจำนวนข้อ)
     * รวมแล้วส่งเนื้อหาได้ราว chars × รอบ × 0.85 ตัวอักษร (เผื่อที่ว่างตอนจัดหัวข้อลงแต่ละรอบ)
     */
    private const MAX_ROUNDS = 6;

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly QuizRepository $quizzes,
        private readonly ReviewRepository $reviews,
        private readonly AiRepository $ai,
        private readonly AiRouter $router,
        private readonly QuizGenerator $generator,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
        private readonly UnitKnowledge $knowledge,
        private readonly AttemptRepository $attempts,
    ) {
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $units = $this->units->forCourse($course['id']);
        $q = $request->getQueryParams();

        $route = $this->tryRoute((int) $user['id'], trim((string) ($q['ai'] ?? '')));

        return $this->view->render($response, 'quiz/create', [
            'page' => 'courses',
            'course' => $course,
            'units' => $units,
            'preselectedUnitId' => isset($q['unit_id']) ? (int) $q['unit_id'] : null,
            'preselectedKind' => $q['kind'] ?? 'posttest',
            'countOptions' => self::COUNT_OPTIONS,
            'typeOptions' => self::TYPE_OPTIONS,
            'levels' => self::LEVELS,
            'aiChip' => $route['chip'],
            'aiWait' => $route['wait'],
            'aiError' => $route['error'],
            'aiOptions' => $this->router->options((int) $user['id']),
            'aiChosen' => trim((string) ($q['ai'] ?? '')),
        ]);
    }

    public function workspace(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $params = $this->readParams($request, $course);

        if ($params['unitTitles'] === [] || $params['types'] === []) {
            Flash::error('กรุณาเลือกหน่วยการเรียนและชนิดข้อสอบอย่างน้อยหนึ่งอย่าง');

            return $this->redirect($response, "/courses/{$course['id']}/quizzes/create");
        }

        $route = $this->tryRoute((int) $user['id'], $params['ai']);

        return $this->view->render($response, 'quiz/workspace', [
            'page' => 'courses',
            'course' => $course,
            'params' => $params,
            'query' => $request->getUri()->getQuery(),
            'aiChip' => $route['chip'],
        ]);
    }

    /** SSE: สตรีมร่างข้อสอบทีละข้อ แล้วบันทึกเป็นแบบทดสอบฉบับร่าง */
    public function stream(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $params = $this->readParams($request, $course);
        $quality = $this->settings->userQualityPref((int) $user['id']);

        ignore_user_abort(true);
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $send = static function (string $event, array $data): void {
            echo 'event: ' . $event . "\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };

        try {
            $route = $this->router->route((int) $user['id'], $quality, real: true, target: $params['ai'], task: 'quiz');
        } catch (AiUnavailableException $e) {
            $send('error', ['reason' => $e->reason, 'message' => $e->getMessage()]);

            return $response;
        }

        // ฐานความรู้จากเนื้อหาจริงของหน่วย — PDF ถูกอ่านไว้ล่วงหน้าตอนอัปโหลด จึงไม่ช้า
        // ยาวเกินที่โมเดลรับไหวในรอบเดียว → แบ่งเป็นหลายรอบ (โมเดลใหญ่ได้รอบละมากกว่า จึงใช้รอบน้อยกว่า)
        $budget = ModelBudget::chars($route);
        $known = $this->knowledge->forUnits($params['unitIds'], (int) ($budget * min($params['count'], self::MAX_ROUNDS) * 0.85));
        $rounds = ModelBudget::rounds(ModelBudget::split($known['text'], $budget), $params['count'], $budget)
            ?: [['', $params['count']]];

        $jobId = $this->ai->createJob([
            'user_id' => (int) $user['id'],
            'course_id' => $course['id'],
            'kind' => 'quiz',
            'source' => $route->source,
            'status' => 'running',
            'prompt' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        // ปลดล็อก session ระหว่างร่าง (แบบเดียวกับแชต) — ไม่อย่างนั้นหน้าอื่นของครูคนนี้จะค้างจนร่างเสร็จ
        // หลังจากนี้อ่าน $_SESSION ได้ตามปกติ (Auth::log ใช้) แต่ห้ามเขียนอะไรลง session อีก
        session_write_close();

        $send('meta', [
            'chip' => $route->chip,
            'total' => $params['count'],
            'source' => $route->source,
            'knowledge' => self::knowledgeNote($known, count($rounds), $budget),
            'jobId' => $jobId,   // ปุ่มยกเลิกใช้สั่งหยุดงานนี้ (cancel())
        ]);

        $collected = [];
        $started = hrtime(true);
        // ครูกดยกเลิก = สถานะงานเป็น cancelled — ตรวจก่อนทุกรอบและทุกข้อ แล้วหยุดโดยไม่บันทึกและไม่ตัดโควตา
        $cancelled = fn (): bool => ($this->ai->findJob($jobId)['status'] ?? '') === 'cancelled';

        foreach ($rounds as $i => [$chunk, $want]) {
            if ($cancelled()) {
                return $this->stopCancelled($response, $send, (int) $user['id'], $jobId, $route, count($collected));
            }
            if (count($rounds) > 1) {
                $send('round', ['no' => $i + 1, 'of' => count($rounds), 'count' => $want]);
            }

            $got = 0;
            try {
                foreach ($this->generator->stream($route, [
                    'course_code' => $course['code'],
                    'course_name' => $course['name'],
                    'lesson_titles' => $params['unitTitles'],
                    'count' => $want,
                    'types' => $params['types'],
                    'level' => $params['level'],
                    'source' => $chunk,
                    // ข้อที่ได้จากรอบก่อน ๆ — กันถามซ้ำเมื่อเนื้อหาสองก้อนคาบเกี่ยวกัน
                    'avoid' => self::avoidList($collected),
                ], $quality) as $ev) {
                    if ($ev['event'] === 'question' && $cancelled()) {
                        return $this->stopCancelled($response, $send, (int) $user['id'], $jobId, $route, count($collected));
                    }
                    if ($ev['event'] === 'question' && $got < $want) {
                        $got++;
                        $ev['data']['no'] = count($collected) + 1;
                        $collected[] = $ev['data'];
                        $send('question', $ev['data']);
                    } elseif ($ev['event'] === 'error') {
                        throw new \RuntimeException($ev['message']);
                    }
                }
            } catch (Throwable $e) {
                // รอบแรกล้มและยังไม่ได้สักข้อ = แจ้งครูเลย · รอบหลังล้ม = เก็บข้อที่ได้ไว้แล้วลองรอบถัดไป
                if ($collected === []) {
                    $send('error', ['reason' => 'generate_failed', 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'ระบบขัดข้องระหว่างร่างข้อสอบ']);
                    $this->ai->updateJob($jobId, ['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => date('Y-m-d H:i:s')]);

                    return $response;
                }
            }
        }

        if ($cancelled()) {
            return $this->stopCancelled($response, $send, (int) $user['id'], $jobId, $route, count($collected));
        }

        if ($collected === []) {
            $send('error', ['reason' => 'empty', 'message' => 'ผู้ช่วยไม่ได้ร่างข้อสอบออกมา กรุณาลองใหม่']);
            $this->ai->updateJob($jobId, ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s')]);

            return $response;
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $quizId = $this->persist($course, $params, $collected, (int) $user['id'], $jobId, $route->source, $durationMs);

        if ($route->countsQuota) {
            $this->ai->consumeQuota((int) $user['id'], $this->period(), $this->settings->int('ai_monthly_quota', 60));
        }
        $this->ai->logUsage((int) $user['id'], $jobId, $route->source, $route->model, true, 'quiz');
        $this->auth->log('ai.quiz.generate', 'quiz#' . $quizId, ['count' => count($collected), 'source' => $route->source]);

        $send('done', [
            'quizId' => $quizId,
            'total' => count($collected),
            'reviewUrl' => Url::to("/courses/{$course['id']}/quizzes/$quizId/review"),
        ]);

        return $response;
    }

    /** ครูกดยกเลิก: ทำเครื่องหมายงานเป็น cancelled แล้ว stream() จะหยุดเองก่อนรอบ/ข้อถัดไป */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'หมดเวลาการใช้งาน กรุณาโหลดหน้าใหม่'], 419);
        }

        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $job = $this->ai->findJob((int) $args['jobId']);
        if ($job === null || (int) $job['user_id'] !== (int) $user['id'] || (int) $job['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request);
        }

        // เสร็จหรือล้มไปแล้วก็ไม่ต้องทำอะไร — ร่างที่บันทึกแล้วยังอยู่ในรายการรอตรวจ
        if (!in_array($job['status'], ['queued', 'running'], true)) {
            return $this->json($response, ['ok' => true, 'status' => $job['status']]);
        }

        $this->ai->updateJob((int) $job['id'], ['status' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s')]);

        return $this->json($response, ['ok' => true, 'status' => 'cancelled']);
    }

    /** หยุดงานที่ครูยกเลิก: ไม่บันทึกร่าง ไม่ตัดโควตา แต่ยังเก็บบันทึกการเรียกโมเดลไว้ */
    private function stopCancelled(Response $response, callable $send, int $userId, int $jobId, AiRoute $route, int $got): Response
    {
        $this->ai->logUsage($userId, $jobId, $route->source, $route->model, false, 'quiz');
        $this->auth->log('ai.quiz.cancel', 'job#' . $jobId, ['got' => $got, 'source' => $route->source]);
        $send('cancelled', ['got' => $got]);

        return $response;
    }

    public function review(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id'], ai: false);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);

        $questions = $this->quizzes->questions($quiz['id']);
        $generation = $this->reviews->findByTarget((int) $user['id'], 'quiz', $quiz['id']);
        $genPayload = $generation ? (json_decode((string) $generation['payload'], true) ?: []) : [];

        $regenQuery = '';
        if (isset($genPayload['params'])) {
            $p = $genPayload['params'];
            $qs = [];
            if (!empty($p['unit_id'])) {
                $qs[] = 'unit_id=' . (int) $p['unit_id'];
            }
            if (!empty($p['kind'])) {
                $qs[] = 'kind=' . rawurlencode($p['kind']);
            }
            foreach ($p['types'] ?? [] as $t) {
                $qs[] = 'types=' . rawurlencode($t);
            }
            $qs[] = 'count=' . (int) ($p['count'] ?? 10);
            $qs[] = 'level=' . rawurlencode((string) ($p['level'] ?? 'กลาง'));
            $regenQuery = implode('&', $qs);
        }

        $aiOn = (int) ($course['ai_quiz_enabled'] ?? 1) === 1;

        return $this->view->render($response, 'quiz/review', [
            'page' => 'courses',
            'course' => $course,
            'quiz' => $quiz,
            'questions' => $questions,
            'manual' => $quiz['source'] === 'manual',
            'aiOn' => $aiOn,
            'aiChip' => $quiz['source'] === 'manual' ? 'สร้างเอง' : $this->sourceChip($generation),
            'createdAgo' => Thai::ago($quiz['created_at']),
            'regenQuery' => $aiOn ? $regenQuery : '',
            'attemptCount' => $this->quizzes->submittedCount((int) $quiz['id']),
            'editorData' => [
                'questions' => array_map(static fn (array $q): array => [
                    'id' => (int) $q['id'],
                    'type' => $q['type'],
                    'question' => (string) $q['question'],
                    'explanation' => (string) ($q['explanation'] ?? ''),
                    'score' => (float) $q['score'],
                    'choices' => array_map(static fn (array $c): array => [
                        'id' => (int) $c['id'],
                        'content' => (string) $c['content'],
                        'is_correct' => (int) $c['is_correct'] === 1,
                    ], $q['choices']),
                ], $questions),
                'labels' => QuizRepository::CHOICE_LABELS,
            ],
        ]);
    }

    /** หน้าสร้างแบบทดสอบเอง (ไม่ใช้ AI): เลือกหน่วย ชนิด และชื่อ แล้วไปเขียนข้อสอบในหน้าแก้ไข */
    public function newForm(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id'], ai: false);
        $q = $request->getQueryParams();

        return $this->view->render($response, 'quiz/new', [
            'page' => 'courses',
            'course' => $course,
            'units' => $this->units->forCourse($course['id']),
            'unitId' => isset($q['unit_id']) ? (int) $q['unit_id'] : 0,
            'kind' => ($q['kind'] ?? '') === 'pretest' ? 'pretest' : 'posttest',
        ]);
    }

    public function createManual(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id'], ai: false);
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, "/courses/{$course['id']}/quizzes/new");
        }

        $unit = null;
        if ((int) ($data['unit_id'] ?? 0) > 0) {
            $unit = $this->units->find((int) $data['unit_id']);
            if ($unit === null || (int) $unit['course_id'] !== (int) $course['id']) {
                throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
            }
        }
        // หน่วยสอบมีแต่แบบทดสอบคิดคะแนน
        $kind = ($data['kind'] ?? '') === 'pretest' && !($unit && UnitRepository::isExam($unit)) ? 'pretest' : 'posttest';

        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 180);
        if ($title === '') {
            $title = match (true) {
                $unit !== null && UnitRepository::isExam($unit) => 'แบบทดสอบ' . $unit['title'] . ' · ' . $course['name'],
                default => ($kind === 'pretest' ? 'แบบทดสอบก่อนเรียน' : 'แบบทดสอบหลังเรียน') . ' · ' . ($unit['title'] ?? $course['name']),
            };
        }

        $fields = [
            'course_id' => $course['id'],
            'title' => $title,
            'attempts_allowed' => 1,
            'source' => 'manual',
            'review_status' => 'draft',
            'created_by' => (int) $user['id'],
            'kind' => $kind,
        ];
        if ($unit !== null) {
            $fields['unit_id'] = (int) $unit['id'];
        }

        // เริ่มด้วยข้อปรนัยว่างหนึ่งข้อ ให้ครูเขียนต่อได้ทันที
        $quizId = $this->quizzes->createWithQuestions($fields, [[
            'type' => 'choice',
            'question' => '',
            'score' => 1,
            'source' => 'manual',
            'choices' => array_map(static fn (string $l): array => ['label' => $l, 'content' => ''], array_slice(QuizRepository::CHOICE_LABELS, 0, 4)),
        ]]);
        $this->auth->log('quiz.create_manual', 'quiz#' . $quizId);

        return $this->redirect($response, "/courses/{$course['id']}/quizzes/$quizId/review");
    }

    public function regenerateOne(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);
        $question = $this->quizzes->getQuestion((int) $args['qid']);

        if ($question === null || (int) $question['quiz_id'] !== (int) $quiz['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบข้อสอบนี้');
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['error' => 'เซสชันหมดอายุ'], 419);
        }

        $generation = $this->reviews->findByTarget((int) $user['id'], 'quiz', (int) $quiz['id']);
        $genPayload = $generation ? (json_decode((string) $generation['payload'], true) ?: []) : [];
        $params = $genPayload['params'] ?? [];

        try {
            $route = $this->router->route((int) $user['id'], $this->settings->userQualityPref((int) $user['id']), real: true, target: (string) ($params['ai'] ?? ''), task: 'quiz');
        } catch (AiUnavailableException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 503);
        }

        $fresh = null;
        foreach ($this->generator->stream($route, [
            'course_code' => $course['code'],
            'course_name' => $course['name'],
            'lesson_titles' => $params['unitTitles'] ?? [],
            'count' => 3,
            'types' => [$question['type'] === 'short_answer' ? 'อัตนัย' : 'ปรนัย'],
            'level' => $params['level'] ?? 'กลาง',
            // ร่างที่สร้างก่อนมี unitIds ใช้หน่วยของแบบทดสอบแทน · เนื้อหายาวใช้ก้อนที่ใกล้กับข้อเดิมที่สุด
            'source' => self::closestChunk(ModelBudget::split($this->knowledge->forUnits(
                array_map('intval', (array) ($params['unitIds'] ?? (empty($params['unit_id']) ? [] : [$params['unit_id']]))),
                ModelBudget::chars($route) * self::MAX_ROUNDS
            )['text'], ModelBudget::chars($route)), (string) $question['question']),
            'avoid' => (string) $question['question'],
        ]) as $ev) {
            if ($ev['event'] === 'question') {
                $fresh = $ev['data'];
                if ($fresh['question'] !== $question['question']) {
                    break;
                }
            }
        }

        if ($fresh === null) {
            return $this->json($response, ['error' => 'ร่างข้อใหม่ไม่สำเร็จ'], 500);
        }

        $this->quizzes->replaceQuestion((int) $question['id'], $fresh);
        $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, true, 'quiz');

        return $this->json($response, ['question' => $this->quizzes->getQuestion((int) $question['id'])]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id'], ai: false);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, "/courses/{$course['id']}/quizzes/{$quiz['id']}/review");
        }

        $payload = json_decode((string) ($data['payload'] ?? ''), true);
        $regraded = 0;
        if (($data['sync'] ?? '') === '1' && is_array($payload)) {
            // หน้าแก้ไขส่งข้อสอบทั้งชุดมา (ข้อที่ไม่อยู่ในชุด = ครูลบออก)
            if ($this->quizzes->syncQuestions((int) $quiz['id'], $payload) && $this->quizzes->submittedCount((int) $quiz['id']) > 0) {
                $regraded = $this->attempts->regradeQuiz((int) $quiz['id']);
            }
        } elseif (is_array($payload)) {
            $this->quizzes->saveEdits((int) $quiz['id'], $payload);
        }

        $meta = [
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 180) ?: $quiz['title'],
            'time_limit_minutes' => max(0, min(600, (int) ($data['time_limit'] ?? $quiz['time_limit_minutes'] ?? 0))) ?: null,
        ];
        $publish = ($data['action'] ?? '') === 'publish';
        $problems = $publish ? $this->quizzes->problems((int) $quiz['id']) : [];
        if ($publish && $this->quizzes->questionCount((int) $quiz['id']) === 0) {
            $problems[] = 'ยังไม่มีข้อสอบสักข้อ';
        }

        // เผยแพร่ไม่ได้ (เช่น ยังไม่ได้เลือกเฉลย): เก็บที่แก้ไว้เป็นร่าง แล้วกลับไปแก้ต่อ
        // ชุดที่เผยแพร่อยู่ก็ซ่อนจากนักเรียนไว้ก่อน ไม่ให้เจอข้อที่ยังไม่มีเฉลย
        if ($problems !== []) {
            $this->quizzes->updateQuiz((int) $quiz['id'], $meta + ['review_status' => 'pending']);
            Flash::error('บันทึกที่แก้ไว้แล้ว แต่ยังเผยแพร่ไม่ได้' . ($quiz['review_status'] === 'published' ? ' (ซ่อนจากนักเรียนไว้ก่อน)' : '') . ': '
                . implode(' · ', array_slice($problems, 0, 6)) . (count($problems) > 6 ? ' …' : ''));

            return $this->redirect($response, "/courses/{$course['id']}/quizzes/{$quiz['id']}/review");
        }

        $this->quizzes->updateQuiz((int) $quiz['id'], $meta + ['review_status' => $publish ? 'published' : 'pending']);
        if ($regraded > 0) {
            Flash::warning(sprintf('เฉลยหรือคะแนนเปลี่ยน — คิดคะแนนนักเรียนที่ทำไปแล้ว %d ครั้งใหม่ให้แล้ว', $regraded));
        }

        $generation = $this->reviews->findByTarget((int) $user['id'], 'quiz', (int) $quiz['id']);
        if ($generation !== null && $publish) {
            $this->reviews->approve((int) $generation['id'], (int) $user['id']);
        }

        $this->auth->log($publish ? 'quiz.publish' : 'quiz.draft', 'quiz#' . $quiz['id']);

        // ร่างจาก AI ที่ยังไม่เผยแพร่กลับไปรายการรอตรวจ · นอกนั้นกลับไปหน้าหน่วยของแบบทดสอบ (ไม่มีหน่วย = แท็บแบบทดสอบของรายวิชา)
        if (!$publish && $generation !== null) {
            Flash::success('เก็บไว้ในรายการรอตรวจแล้ว');

            return $this->redirect($response, '/review');
        }
        Flash::success($publish ? 'บันทึกเป็นแบบทดสอบแล้ว' : 'เก็บเป็นฉบับร่างแล้ว · นักเรียนยังไม่เห็นแบบทดสอบนี้');

        $unit = (int) ($quiz['unit_id'] ?? 0) > 0 ? $this->units->find((int) $quiz['unit_id']) : null;
        if ($unit !== null && (int) $unit['course_id'] === (int) $course['id']) {
            return $this->redirect($response, "/courses/{$course['id']}/units/{$unit['id']}/edit");
        }

        return $this->redirect($response, "/courses/{$course['id']}?tab=quizzes");
    }

    public function deleteQuestion(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id'], ai: false);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['error' => 'เซสชันหมดอายุ'], 419);
        }

        $this->quizzes->deleteQuestion((int) $quiz['id'], (int) $args['qid']);

        return $this->json($response, ['ok' => true, 'remaining' => $this->quizzes->questionCount((int) $quiz['id'])]);
    }

    // ---------------------------------------------------------------- ภายใน

    private function persist(array $course, array $params, array $questions, int $userId, int $jobId, string $source, int $durationMs): int
    {
        $kindLabel = $params['kind'] === 'pretest' ? 'แบบทดสอบก่อนเรียน' : 'แบบทดสอบหลังเรียน';
        $title = sprintf('%s · %s%s', $kindLabel, $course['name'], $this->unitRange($params['unitTitles']));
        if (!empty($params['exam'])) {
            $title = sprintf('แบบทดสอบ%s · %s', $params['exam'], $course['name']);
        }

        $quizFields = [
            'course_id' => $course['id'],
            'title' => $title,
            'instructions' => null,
            'attempts_allowed' => 1,
            'source' => 'ai',
            'review_status' => 'draft',
            'created_by' => $userId,
            'kind' => $params['kind'],
        ];
        if ($params['unit_id'] !== null) {
            $quizFields['unit_id'] = $params['unit_id'];
        }

        $quizId = $this->quizzes->createWithQuestions($quizFields, array_map(static fn (array $q): array => $q + ['source' => 'ai'], $questions));

        $this->ai->updateJob($jobId, [
            'status' => 'done',
            'duration_ms' => $durationMs,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        $genId = $this->reviews->record($userId, $jobId, 'quiz', $quizId, [
            'title' => $title,
            'summary' => sprintf('%d ข้อ · %s', count($questions), implode(', ', $params['types'])),
            'ai_mode' => $source === 'byok' ? 'โหมดเร็ว' : null,
            'params' => ['unitTitles' => $params['unitTitles'], 'unitIds' => $params['unitIds'], 'unit_id' => $params['unit_id'], 'kind' => $params['kind'], 'types' => $params['types'], 'count' => $params['count'], 'level' => $params['level'], 'ai' => $params['ai']],
        ]);
        $this->reviews->linkTarget($genId, 'quiz', $quizId);

        return $quizId;
    }

    /** @return array{unit_id:?int,kind:string,unitTitles:list<string>,count:int,types:list<string>,level:string} */
    private function readParams(Request $request, array $course): array
    {
        $q = $request->getQueryParams();
        $unitId = isset($q['unit_id']) && $q['unit_id'] !== '' ? (int) $q['unit_id'] : null;
        $kind = in_array($q['kind'] ?? '', ['pretest', 'posttest'], true) ? $q['kind'] : 'posttest';

        $unitTitles = [];
        $unitIds = [];
        $examLabel = null;
        if ($unitId !== null) {
            $unit = $this->units->find($unitId);
            if ($unit !== null && (int) $unit['course_id'] === (int) $course['id']) {
                $unitTitles = [$unit['title']];
                $unitIds = [(int) $unit['id']];
                if (UnitRepository::isExam($unit)) {
                    // หน่วยสอบ: ออกข้อสอบครอบคลุมหน่วยการเรียนก่อนหน้า (กลางภาค = หน่วยที่อยู่ก่อนหน้า, ปลายภาค = ทุกหน่วย)
                    $examLabel = UnitRepository::KINDS[$unit['kind']];
                    $kind = 'posttest';
                    $scope = $this->examScope($course, $unit);
                    $unitTitles = array_column($scope, 'title') ?: [$course['name']];
                    $unitIds = array_map('intval', array_column($scope, 'id'));
                }
            } else {
                $unitId = null;
            }
        }

        if ($unitTitles === []) {
            // fallback: ใช้ชื่อรายวิชาเป็น context ให้ AI
            $unitTitles = [$course['name']];
        }

        $types = array_values(array_intersect(self::TYPE_OPTIONS, (array) ($q['types'] ?? [])));
        $count = in_array((int) ($q['count'] ?? 0), self::COUNT_OPTIONS, true) ? (int) $q['count'] : 10;
        $level = array_key_exists((string) ($q['level'] ?? ''), self::LEVELS) ? (string) $q['level'] : 'กลาง';

        return [
            'unit_id' => $unitId,
            'kind' => $kind,
            'exam' => $examLabel,
            'unitTitles' => $unitTitles,
            'unitIds' => $unitIds,
            'count' => $count,
            'types' => $types ?: ['ปรนัย'],
            'level' => $level,
            // AI ที่ครูเลือกในหน้าสร้างข้อสอบ ('' = ค่าเริ่มต้นของครู)
            'ai' => mb_substr(trim((string) ($q['ai'] ?? '')), 0, 220),
        ];
    }

    /** @return list<array<string,mixed>> หน่วยการเรียนที่หน่วยสอบนี้ครอบคลุม */
    private function examScope(array $course, array $examUnit): array
    {
        $scope = [];
        foreach ($this->units->forCourse((int) $course['id']) as $u) {
            if ((int) $u['id'] === (int) $examUnit['id']) {
                if ($examUnit['kind'] === 'midterm') {
                    break;
                }
                continue;
            }
            if (!UnitRepository::isExam($u)) {
                $scope[] = $u;
            }
        }

        return $scope;
    }

    /** โจทย์ของข้อที่ได้แล้ว (ตัดสั้น) สำหรับบอกโมเดลไม่ให้ถามซ้ำ */
    private static function avoidList(array $collected): string
    {
        $stems = array_map(
            static fn (array $q): string => '- ' . mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $q['question'])), 0, 120),
            array_slice($collected, -20)
        );

        return implode("\n", $stems);
    }

    /**
     * ก้อนเนื้อหาที่ตรงกับโจทย์เดิมมากที่สุด (นับคำ 3 ตัวอักษรที่พบร่วมกัน) — ใช้ร่างข้อใหม่แทนข้อเดิมในเรื่องเดียวกัน
     *
     * @param list<string> $chunks
     */
    private static function closestChunk(array $chunks, string $question): string
    {
        if (count($chunks) <= 1) {
            return $chunks[0] ?? '';
        }

        $q = (string) preg_replace('/\s+/u', '', $question);
        $grams = [];
        for ($i = 0, $n = mb_strlen($q) - 2; $i < $n; $i++) {
            $grams[mb_substr($q, $i, 3)] = true;
        }

        $best = 0;
        $bestScore = -1;
        foreach ($chunks as $i => $chunk) {
            $score = 0;
            foreach (array_keys($grams) as $g) {
                $score += str_contains($chunk, $g) ? 1 : 0;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$i, $score];
            }
        }

        return $chunks[$best];
    }

    /** ข้อความบอกครูว่าผู้ช่วยใช้เนื้อหาอะไรเป็นฐานความรู้ */
    private static function knowledgeNote(array $known, int $rounds = 1, int $budget = 0): string
    {
        if ($known['text'] === '') {
            return 'หน่วยนี้ยังไม่มีเนื้อหาให้อ่าน · ผู้ช่วยออกข้อสอบจากชื่อหน่วยอย่างเดียว เพิ่มเนื้อหาในหน่วยก่อนจะได้ข้อสอบที่ตรงกว่า';
        }

        $note = sprintf('ออกจากเนื้อหาจริงของ %d หน่วย · %s ตัวอักษร', $known['units'], number_format(mb_strlen($known['text'])));
        if ($known['pdf_ok'] > 0) {
            $note .= sprintf(' · รวม PDF %d ไฟล์', $known['pdf_ok']);
        }
        if ($known['pdf_failed'] !== []) {
            $note .= ' · อ่าน PDF ไม่ได้: ' . implode(', ', array_slice($known['pdf_failed'], 0, 3));
        }
        if (($known['pdf_pending'] ?? []) !== []) {
            $note .= ' · PDF สแกนที่ยังรอ AI อ่าน (OCR) ยังไม่ได้ใช้: ' . implode(', ', array_slice($known['pdf_pending'], 0, 3));
        }
        if ($known['videos'] > 0) {
            $note .= ' · วีดิโอใช้แค่ชื่อเรื่อง';
        }
        if ($rounds > 1) {
            $note .= sprintf(' · แบ่งออกข้อสอบ %d รอบ (รอบละไม่เกิน %s ตัวอักษร ตามขนาดโมเดล)', $rounds, number_format($budget));
        }
        if ($known['trimmed']) {
            $note .= ' · เนื้อหายาวเกิน ตัดบางส่วนออก';
        }

        return $note;
    }

    private function unitRange(array $titles): string
    {
        if ($titles === []) {
            return '';
        }
        if (count($titles) === 1) {
            return ' · ' . $titles[0];
        }

        return sprintf(' · %d หน่วย', count($titles));
    }

    /** @return array{chip:string,wait:string,error:?array{reason:string,message:string}} */
    private function tryRoute(int $userId, string $target = ''): array
    {
        try {
            $route = $this->router->route($userId, $this->settings->userQualityPref($userId), real: true, target: $target, task: 'quiz');

            return ['chip' => $route->chip, 'wait' => $route->wait, 'error' => null];
        } catch (AiUnavailableException $e) {
            return [
                'chip' => 'ผู้ช่วย AI ยังใช้งานไม่ได้',
                'wait' => $e->getMessage(),
                'error' => ['reason' => $e->reason, 'message' => $e->getMessage()],
            ];
        }
    }

    private function sourceChip(?array $generation): string
    {
        if ($generation === null) {
            return 'AI ส่วนกลาง';
        }
        $payload = json_decode((string) ($generation['payload'] ?? '{}'), true) ?: [];

        return isset($payload['ai_mode']) && $payload['ai_mode']
            ? 'AI ของฉัน · ' . $payload['ai_mode']
            : 'AI ส่วนกลาง';
    }

    private function period(): string
    {
        return sprintf('%04d-%02d', $this->settings->int('academic_year', (int) date('Y')), (int) date('n'));
    }

    /** @return array<string,mixed> */
    private function ownedCourse(Request $request, int $courseId, int $teacherId, bool $ai = true): array
    {
        $course = $this->courses->find($courseId);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== $teacherId) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }
        // สร้าง/แก้แบบทดสอบเองไม่ต้องใช้ AI — สวิตช์ของผู้ดูแลปิดเฉพาะงานที่เรียก AI
        if ($ai && (int) ($course['ai_quiz_enabled'] ?? 1) !== 1) {
            throw new HttpForbiddenException($request, 'ผู้ดูแลระบบยังไม่เปิดใช้งานฟังก์ชันออกข้อสอบด้วย AI สำหรับรายวิชานี้');
        }

        return $course;
    }

    /** @return array<string,mixed> */
    private function ownedQuiz(Request $request, int $quizId, int $courseId): array
    {
        $quiz = $this->quizzes->find($quizId);
        if ($quiz === null || (int) $quiz['course_id'] !== $courseId) {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }

        return $quiz;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
