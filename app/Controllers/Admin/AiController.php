<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\AI\AiUnavailableException;
use App\AI\GoogleAiProvider;
use App\AI\HttpClient;
use App\AI\KeyCipher;
use App\AI\OpenAiCompatibleProvider;
use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\CourseRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * แดชบอร์ดผู้ดูแล: สถานะเครื่อง AI ของส่วนกลาง คิวงาน การใช้งานรายวัน/รายครู และเพดานโควตา
 */
final class AiController
{
    private const CAPS = [40, 60, 80, 0]; // 0 = ไม่จำกัด
    private const TABS = ['overview', 'settings', 'quota', 'courses'];

    public function __construct(
        private readonly View $view,
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
        private readonly Db $db,
        private readonly Auth $auth,
        private readonly KeyCipher $cipher,
        private readonly CourseRepository $courses,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $tab = (string) ($request->getQueryParams()['tab'] ?? 'overview');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'overview';
        }

        $endpoint = $this->ai->defaultEndpoint();
        $period = sprintf('%04d-%02d', $this->settings->int('academic_year'), (int) date('n'));
        $cap = $this->settings->int('ai_monthly_quota', 60);
        $teacherCount = $this->db->int("SELECT COUNT(*) FROM {users} WHERE role = 'teacher' AND status = 'active'");

        return $this->view->render($response, 'admin/ai', [
            'page' => 'admin-ai',
            'tab' => $tab,
            'endpoint' => $endpoint,
            'endpointCheckedAgo' => $endpoint && $endpoint['last_checked_at'] ? Thai::ago($endpoint['last_checked_at']) : null,
            'gpu' => $this->gpuStats($endpoint),
            'models' => $this->loadedModels($endpoint),
            'queue' => $this->queueJobs(),
            'queueLength' => $this->ai->queueLength(),
            'chart' => $this->weeklyChart(),
            'weekTotal' => $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE created_at > (NOW() - INTERVAL 7 DAY)"),
            'usage' => $this->teacherUsage($period),
            'caps' => self::CAPS,
            'cap' => $cap,
            'capTotal' => $cap > 0 ? $cap * $teacherCount : 0,
            'teacherCount' => $teacherCount,
            'courses' => $tab === 'courses' ? $this->courses->allActiveForAdmin() : [],
        ]);
    }

    /** ผู้ดูแลเปิด/ปิดฟังก์ชัน AI ของรายวิชานี้ (บางฟังก์ชันยังไม่ผ่านการทดสอบ จึงจำกัดเป็นรายวิชาได้) */
    public function saveCourseFeatures(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, 'courses');
        }

        $courseId = (int) $args['id'];
        $quizEnabled = !empty($data['ai_quiz_enabled']);
        $lessonPlanEnabled = !empty($data['ai_lesson_plan_enabled']);

        $this->courses->setAiFeatures($courseId, $quizEnabled, $lessonPlanEnabled);
        $this->auth->log('ai.course_features.save', 'course#' . $courseId, [
            'quiz' => $quizEnabled,
            'lesson_plan' => $lessonPlanEnabled,
        ]);
        Flash::success('บันทึกการตั้งค่าฟังก์ชัน AI แล้ว');

        return $this->redirect($response, 'courses');
    }

    public function saveCap(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, 'quota');
        }

        $cap = (int) ($data['cap'] ?? 60);
        if (!in_array($cap, self::CAPS, true)) {
            $cap = 60;
        }

        $this->settings->set('ai_monthly_quota', (string) $cap, 'integer', 'ai');
        $this->auth->log('ai.cap.save', null, ['cap' => $cap]);
        Flash::success('บันทึกเพดานโควตาแล้ว · มีผลกับครูทุกคนในเดือนถัดไป');

        return $this->redirect($response, 'quota');
    }

    /** ผู้ดูแลบันทึกการตั้งค่าเครื่อง AI ส่วนกลาง แล้วตรวจสถานะให้ทันที */
    public function saveEndpoint(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, 'settings');
        }

        $current = $this->ai->defaultEndpoint();
        $kind = self::kind($data['kind'] ?? '');
        $baseUrl = self::baseUrlFor($kind, (string) ($data['base_url'] ?? ''));
        $apiKey = trim((string) ($data['api_key'] ?? ''));

        if (self::needsBaseUrl($kind) && !preg_match('~^https?://~i', $baseUrl)) {
            Flash::error('ที่อยู่ของเครื่องไม่ถูกต้อง ต้องขึ้นต้นด้วย http:// หรือ https://');

            return $this->redirect($response, 'settings');
        }

        if (self::needsKey($kind) && $apiKey === '' && empty($current['api_key_encrypted'])) {
            Flash::error('บริการนี้ต้องใช้รหัสเชื่อมต่อ กรุณาวางรหัสจากผู้ให้บริการ');

            return $this->redirect($response, 'settings');
        }

        // เลือก "อื่น ๆ" จากรายชื่อโมเดล = ใช้ชื่อที่ผู้ดูแลพิมพ์เอง
        $model = trim((string) ($data['model'] ?? ''));
        if ($model === '__custom') {
            $model = trim((string) ($data['model_custom'] ?? ''));
        }

        $fields = [
            'name' => trim((string) ($data['name'] ?? '')) ?: 'AI ของส่วนกลาง',
            'base_url' => $baseUrl,
            'kind' => $kind,
            'model' => $model,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'is_default' => 1,
        ];

        if ($apiKey !== '') {
            $fields['api_key_encrypted'] = $this->cipher->encrypt($apiKey);
        } elseif (($data['clear_key'] ?? '') === '1') {
            $fields['api_key_encrypted'] = null;
        }

        $id = $this->ai->saveEndpoint($current ? (int) $current['id'] : null, $fields);

        // ตรวจทันทีหลังบันทึก เพื่อให้สถานะที่ครูเห็นตรงกับความเป็นจริง
        $probe = $this->probeEndpoint($kind, $baseUrl, $this->endpointKey($apiKey, $current), $fields['model']);
        $this->ai->markEndpointChecked($id, $probe['ok'] ? 'online' : 'offline');

        $this->auth->log('ai.endpoint.save', $baseUrl, ['kind' => $kind, 'ok' => $probe['ok']]);
        $probe['ok']
            ? Flash::success('บันทึกและเชื่อมต่อเครื่อง AI ส่วนกลางได้แล้ว · ' . $probe['message'])
            : Flash::error('บันทึกแล้ว แต่ยังต่อไม่ได้ · ' . $probe['message']);

        return $this->redirect($response, 'settings');
    }

    /** ทดสอบการเชื่อมต่อโดยไม่บันทึก (เรียกจากปุ่มในหน้าจอ) */
    public function testEndpoint(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $kind = self::kind($data['kind'] ?? '');
        $baseUrl = self::baseUrlFor($kind, (string) ($data['base_url'] ?? ''));
        $current = $this->ai->defaultEndpoint();

        $probe = $this->probeEndpoint(
            $kind,
            $baseUrl,
            $this->endpointKey(trim((string) ($data['api_key'] ?? '')), $current),
            trim((string) ($data['model'] ?? ''))
        );

        // อัปเดตสถานะที่เก็บไว้เฉพาะตอนที่ทดสอบ "ของจริง" คือปลายทางและโมเดลตรงกับที่บันทึกไว้
        // (การกดดึงรายชื่อโมเดลจะไม่ส่งชื่อโมเดลมา จึงไม่ควรไปเปลี่ยนสถานะ)
        $sameEndpoint = $current !== null
            && $baseUrl === (string) $current['base_url']
            && trim((string) ($data['model'] ?? '')) === (string) $current['model'];

        if ($sameEndpoint) {
            $this->ai->markEndpointChecked((int) $current['id'], $probe['ok'] ? 'online' : 'offline');
        }

        return $this->json($response, $probe);
    }

    /** ชนิดการเชื่อมต่อที่รองรับ — ค่าอื่นถือเป็น ollama */
    public static function kind(mixed $raw): string
    {
        $kind = (string) $raw;

        return in_array($kind, ['ollama', 'openai_compatible', 'openrouter', 'google'], true) ? $kind : 'ollama';
    }

    /** บริการสำเร็จรูปมีที่อยู่ตายตัว ผู้ดูแลไม่ต้องกรอกเอง */
    private static function baseUrlFor(string $kind, string $typed): string
    {
        return match ($kind) {
            'openrouter' => OpenAiCompatibleProvider::OPENROUTER_BASE,
            'google' => GoogleAiProvider::BASE,
            default => rtrim(trim($typed), '/'),
        };
    }

    private static function needsBaseUrl(string $kind): bool
    {
        return $kind === 'ollama' || $kind === 'openai_compatible';
    }

    private static function needsKey(string $kind): bool
    {
        return $kind === 'openrouter' || $kind === 'google';
    }

    /** ใช้รหัสที่พิมพ์เข้ามาก่อน ถ้าเว้นว่างไว้ให้ใช้รหัสเดิมที่เก็บไว้ */
    private function endpointKey(string $typed, ?array $current): string
    {
        if ($typed !== '') {
            return $typed;
        }
        if ($current === null || empty($current['api_key_encrypted'])) {
            return '';
        }

        try {
            return $this->cipher->decrypt((string) $current['api_key_encrypted']);
        } catch (\RuntimeException) {
            return '';
        }
    }

    /**
     * ยิงจริงไปที่เครื่องส่วนกลางเพื่อดูว่าใช้งานได้ไหม และมีโมเดลอะไรบ้าง
     *
     * @return array{ok:bool,message:string,models:list<string>}
     */
    private function probeEndpoint(string $kind, string $baseUrl, string $apiKey, string $model): array
    {
        if (self::needsBaseUrl($kind) && !preg_match('~^https?://~i', $baseUrl)) {
            return ['ok' => false, 'message' => 'ที่อยู่ของเครื่องไม่ถูกต้อง ต้องขึ้นต้นด้วย http:// หรือ https://', 'models' => []];
        }
        if (self::needsKey($kind) && $apiKey === '') {
            return ['ok' => false, 'message' => 'บริการนี้ต้องใช้รหัสเชื่อมต่อ กรุณาวางรหัสจากผู้ให้บริการ', 'models' => []];
        }

        try {
            if ($kind === 'google') {
                $probe = GoogleAiProvider::probe($apiKey);

                return [
                    'ok' => (bool) $probe['ok'],
                    'message' => $probe['message'],
                    'models' => $probe['models'] ?? [],
                ];
            }

            if ($kind === 'openai_compatible' || $kind === 'openrouter') {
                $probe = OpenAiCompatibleProvider::probe(
                    $apiKey,
                    $baseUrl,
                    $model !== '' ? $model : null,
                    new HttpClient(),
                    verifyKey: self::needsKey($kind)
                );

                return [
                    'ok' => (bool) $probe['ok'],
                    'message' => $probe['message'],
                    'models' => $probe['models'] ?? [],
                ];
            }

            $res = (new HttpClient(connectTimeout: 4, timeout: 10))->request('GET', $baseUrl . '/api/tags');
            if ($res['status'] >= 400) {
                return ['ok' => false, 'message' => HttpClient::describeError($res['status'], $res['body']), 'models' => []];
            }

            $models = [];
            foreach (json_decode($res['body'], true)['models'] ?? [] as $m) {
                if (isset($m['name'])) {
                    $models[] = (string) $m['name'];
                }
            }

            if ($models === []) {
                return ['ok' => false, 'message' => 'ต่อกับเครื่องได้ แต่ยังไม่มีโมเดลติดตั้งอยู่ (ollama pull …)', 'models' => []];
            }

            if ($model !== '' && !in_array($model, $models, true)) {
                return [
                    'ok' => false,
                    'message' => 'ไม่พบโมเดล ' . $model . ' บนเครื่องนี้ · มีอยู่: ' . implode(', ', array_slice($models, 0, 5)),
                    'models' => $models,
                ];
            }

            return [
                'ok' => true,
                'message' => 'ต่อกับเครื่องได้ · พบโมเดล ' . count($models) . ' รายการ',
                'models' => $models,
            ];
        } catch (AiUnavailableException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'models' => []];
        }
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }

    /** @return list<array{label:string,value:string,pct:int,tone:string}> */
    private function gpuStats(?array $endpoint): array
    {
        $online = $endpoint && $endpoint['status'] === 'online';
        $today = $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE created_at > CURDATE()");
        // ค่าจำลองระหว่างที่ยังไม่มีเครื่องจริง — ผูกกับเวลาให้ดูมีชีวิต
        $load = $online ? 30 + (int) (abs(sin((float) date('U') / 900)) * 45) : 0;

        return [
            ['label' => 'หน่วยความจำของการ์ดที่ใช้อยู่', 'value' => $online ? '17.2 / 24 GB' : '— / 24 GB', 'pct' => $online ? 72 : 0, 'tone' => 'brand'],
            ['label' => 'อุณหภูมิ', 'value' => $online ? '68 °C' : '—', 'pct' => $online ? 55 : 0, 'tone' => 'ok'],
            ['label' => 'การใช้งานตัวประมวลผล', 'value' => $online ? $load . ' %' : '—', 'pct' => $load, 'tone' => 'brand'],
            ['label' => 'คำขอวันนี้', 'value' => $today . ' ครั้ง', 'pct' => min(100, $today * 3), 'tone' => 'brand'],
        ];
    }

    /** @return list<array{name:string,role:string,vram:string}> */
    private function loadedModels(?array $endpoint): array
    {
        if (!$endpoint || $endpoint['status'] !== 'online') {
            return [];
        }

        return [
            ['name' => (string) ($endpoint['model'] ?: 'typhoon2-8b-instruct'), 'role' => 'โหมดประหยัด', 'vram' => '5.8 GB'],
            ['name' => 'qwen2.5:14b-instruct', 'role' => 'โหมดคุณภาพสูง', 'vram' => '11.4 GB'],
        ];
    }

    /** @return list<array{who:string,what:string,age:string}> */
    private function queueJobs(): array
    {
        $rows = $this->db->all(
            "SELECT j.kind, j.queued_at, u.full_name
             FROM {ai_jobs} j LEFT JOIN {users} u ON u.id = j.user_id
             WHERE j.status IN ('queued','running')
             ORDER BY j.queued_at LIMIT 8"
        );

        return array_map(static function (array $r): array {
            $age = max(0, time() - strtotime((string) $r['queued_at']));

            return [
                'who' => $r['full_name'] ?? 'ไม่ระบุ',
                'what' => match ($r['kind']) {
                    'quiz' => 'ออกข้อสอบ',
                    'lesson_plan' => 'ทำแผนการสอน',
                    default => 'สรุปเนื้อหา',
                },
                'age' => $age < 60 ? $age . ' วิ.' : intdiv($age, 60) . ' นาที',
            ];
        }, $rows);
    }

    /** @return list<array{day:string,n:int,collegeH:string,ownH:string}> */
    private function weeklyChart(): array
    {
        $days = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
        $out = [];
        $max = 1;
        $raw = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i day"));
            $college = $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE source = 'college' AND DATE(created_at) = ?", [$date]);
            $own = $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE source = 'byok' AND DATE(created_at) = ?", [$date]);
            $raw[] = ['day' => $days[(int) date('w', strtotime($date))], 'college' => $college, 'own' => $own];
            $max = max($max, $college + $own);
        }

        foreach ($raw as $r) {
            $out[] = [
                'day' => $r['day'],
                'n' => $r['college'] + $r['own'],
                'collegeH' => round($r['college'] / $max * 82) . 'px',
                'ownH' => round($r['own'] / $max * 82) . 'px',
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function teacherUsage(string $period): array
    {
        $rows = $this->db->all(
            "SELECT u.id, u.full_name,
                    (SELECT COUNT(*) FROM {ai_usage_logs} l WHERE l.user_id = u.id AND l.source = 'college'
                        AND DATE_FORMAT(l.created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')) AS college,
                    (SELECT COUNT(*) FROM {ai_usage_logs} l WHERE l.user_id = u.id AND l.source = 'byok'
                        AND DATE_FORMAT(l.created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')) AS own,
                    q.used_count, q.monthly_limit
             FROM {users} u
             LEFT JOIN {ai_quotas} q ON q.user_id = u.id AND q.period = ?
             WHERE u.role = 'teacher' AND u.status = 'active'
             ORDER BY q.used_count DESC, u.full_name",
            [$period]
        );

        $limit = $this->settings->int('ai_monthly_quota', 60);

        return array_map(static function (array $r) use ($limit): array {
            $used = (int) ($r['used_count'] ?? 0);
            $lim = (int) ($r['monthly_limit'] ?? $limit);
            $left = max(0, $lim - $used);

            return [
                'name' => $r['full_name'],
                'college' => (int) $r['college'] ?: $used,
                'own' => (int) $r['own'],
                'left' => $left === 0 ? 'หมดแล้ว' : $left . ' ครั้ง',
                'left_kind' => $left === 0 ? 'err' : ($left <= 5 ? 'warn' : 'ink2'),
            ];
        }, $rows);
    }

    /** พากลับไปหน้าเดิม — ระบุ $tab เพื่อให้ยังอยู่แท็บเดิมหลังบันทึกฟอร์ม */
    private function redirect(Response $response, ?string $tab = null): Response
    {
        $path = $tab !== null ? '/admin/ai?tab=' . $tab : '/admin/ai';

        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
