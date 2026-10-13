<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiRouter;
use App\AI\AiUnavailableException;
use App\AI\JsonStream;
use App\Auth\Auth;
use App\Auth\Roles;
use App\Domain\AiRepository;
use App\Domain\CourseRepository;
use App\Domain\SettingsRepository;
use App\Domain\UnitRepository;
use App\Support\Csrf;
use App\Support\TextExtract;
use App\Support\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * ช่องสนทนาลอยกับผู้ช่วย AI — ถามได้ทุกหน้า และครูสั่งให้ช่วยสร้างรายวิชาได้
 * ไม่ตัดโควตาของส่วนกลาง แต่บันทึกการใช้งานตามจริง และงานที่เขียนข้อมูลต้องให้ครูกดยืนยันก่อนเสมอ
 */
final class AiChatController
{
    /** ความยาวข้อความสูงสุดต่อครั้ง — ยาวพอให้ครูวางคำอธิบายรายวิชาทั้งหน้าได้ */
    private const MAX_MESSAGE = 8000;

    /** คำอธิบายรายวิชาที่รับจาก AI — เต็มความยาวที่ครูวางมาได้ ห้ามตัดสั้นกว่าข้อความต้นฉบับ */
    private const MAX_DESCRIPTION = 8000;

    public function __construct(
        private readonly AiRouter $router,
        private readonly AiRepository $ai,
        private readonly Auth $auth,
        private readonly SettingsRepository $settings,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
    ) {
    }

    /**
     * สถานะเส้นทาง AI ปัจจุบัน สำหรับแสดง chip ตอนเปิดช่องสนทนา
     * พร้อมรายการ AI ที่เลือกใช้ได้ (options) ให้ช่องสนทนาแสดงตัวเลือกสลับ AI
     */
    public function status(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $uid = (int) $user['id'];
        $target = trim((string) ($request->getQueryParams()['ai'] ?? ''));
        $extra = [
            'options' => $this->router->options($uid),
            'default' => $this->router->defaultTarget($uid),
        ];

        try {
            $quality = $this->settings->userQualityPref($uid);
            $route = $this->router->route($uid, $quality, false, real: true, target: $target);

            return $this->json($response, [
                'ok' => true,
                'chip' => $route->chip,
                'source' => $route->source,
                'model' => $route->model,
                'target' => $route->target,
            ] + $extra);
        } catch (AiUnavailableException $e) {
            return $this->json($response, [
                'ok' => false,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ] + $extra);
        }
    }

    public function stream(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        if (!Csrf::check($params['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        // ข้อความมาจาก prepare() ผ่านเซสชัน (ใช้ครั้งเดียวแล้วทิ้ง) — ไม่ผ่าน URL เพราะยาวเกินขีดจำกัด
        $prompt = $this->pullPrompt((string) ($params['p'] ?? ''));
        $message = trim($prompt['message'] !== '' ? $prompt['message'] : (string) ($params['q'] ?? ''));
        $attachIds = $prompt['attach'] !== '' ? $prompt['attach'] : (string) ($params['attach'] ?? '');

        if ($message === '' && $attachIds === '') {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้พิมพ์ข้อความ'], 422);
        }
        $message = mb_substr($message, 0, self::MAX_MESSAGE);

        // ต่อเนื้อหาจากไฟล์ที่ครูแนบไว้เข้าไปเป็นบริบท ก่อนปิดเซสชัน
        $attached = $this->attachedText($attachIds);

        // ปล่อยล็อกเซสชันก่อนสตรีม ไม่งั้นหน้าอื่นจะค้างรอจนกว่าจะตอบจบ
        session_write_close();

        ignore_user_abort(true);
        @set_time_limit(300);
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

        $quality = $this->settings->userQualityPref((int) $user['id']);

        // AI ที่ครูเลือกในช่องสนทนา (ว่าง = ค่าเริ่มต้น) — ถ้าใช้ไม่ได้ ระบบสลับตัวอื่นให้และบอกใน meta
        $wanted = trim((string) ($params['ai'] ?? ''));

        try {
            $route = $this->router->route((int) $user['id'], $quality, false, real: true, target: $wanted);
        } catch (AiUnavailableException $e) {
            $send('error', ['reason' => $e->reason, 'message' => $e->getMessage()]);

            return $response;
        }

        $send('meta', [
            'chip' => $route->chip,
            'source' => $route->source,
            'target' => $route->target,
            'switched' => $wanted !== '' && $wanted !== $route->target,
        ]);

        $spec = json_encode([
            'task' => 'chat',
            'message' => $attached === '' ? $message : $attached . "\n\n---\n\nคำถามหรือคำสั่งของครู:\n" . $message,
        ], JSON_UNESCAPED_UNICODE);
        $isTeacher = Roles::teaches($user['role'] ?? null);

        $chars = 0;
        $mode = null;      // null = ยังไม่รู้ว่าเป็นข้อความหรือคำสั่ง, 'text', 'action'
        $pending = '';     // ข้อความต้นเรื่องที่พักไว้ระหว่างตัดสินใจ
        $rawAction = '';

        try {
            foreach ($route->provider->stream($this->systemPrompt($isTeacher), $spec, $quality) as $chunk) {
                $chunk = (string) $chunk;
                if ($chunk === '') {
                    continue;
                }

                if ($mode === 'action') {
                    $rawAction .= $chunk;

                    continue;
                }

                $pending .= $chunk;

                if ($mode === null) {
                    $probe = preg_replace('/^```(?:json)?\s*/i', '', ltrim($pending)) ?? '';
                    if ($probe === '') {
                        continue;
                    }
                    if (str_starts_with($probe, '{')) {
                        $mode = 'action';
                        $rawAction = $probe;
                        $send('working', ['message' => 'กำลังร่างข้อมูลให้…']);

                        continue;
                    }
                    // ไม่ใช่ JSON แน่แล้ว — ปล่อยข้อความที่พักไว้ออกไปทั้งหมด
                    $mode = 'text';
                }

                $chars += mb_strlen($pending);
                $send('delta', ['text' => $pending]);
                $pending = '';
            }

            if ($mode === 'action') {
                $action = $this->parseAction($rawAction, $isTeacher, (int) $user['id']);
                if ($action !== null) {
                    $send('action', $action);
                } else {
                    // โมเดลตอบเป็น JSON แต่ใช้ไม่ได้ — แสดงเป็นข้อความไปตามตรง
                    $chars += mb_strlen($rawAction);
                    $send('delta', ['text' => $rawAction]);
                }
            } elseif ($pending !== '') {
                $chars += mb_strlen($pending);
                $send('delta', ['text' => $pending]);
            }
        } catch (AiUnavailableException $e) {
            // ข้อผิดพลาดจากบริการจริง (รหัสหมดอายุ โควตาผู้ให้บริการ ฯลฯ) — บอกสาเหตุตามจริง
            $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, false);
            $send('error', ['reason' => $e->reason, 'message' => $e->getMessage()]);

            return $response;
        } catch (Throwable $e) {
            $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, false);
            $send('error', ['reason' => 'generate_failed', 'message' => 'ตอบกลับไม่สำเร็จ กรุณาลองใหม่']);

            return $response;
        }

        $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, $chars > 0);
        $this->auth->log('ai.chat.test', null, ['source' => $route->source, 'quality' => $quality, 'chars' => $chars]);

        $send('done', ['chars' => $chars]);

        return $response;
    }

    /**
     * รับข้อความที่ครูพิมพ์มาเก็บไว้ในเซสชันก่อน แล้วคืน id ให้หน้าจอไปเปิดสตรีมต่อ
     *
     * EventSource เปิดได้เฉพาะ GET และข้อความภาษาไทยยาว ๆ จะทำให้ URL เกินขีดจำกัดของเว็บเซิร์ฟเวอร์
     * (ไทย 1 ตัวอักษร = 9 ไบต์เมื่อ encode ใส่ URL ทำให้ 1,000 ตัวอักษรก็ชน 414 URI Too Long แล้ว)
     */
    public function prepare(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $message = trim((string) ($data['q'] ?? ''));
        $attach = trim((string) ($data['attach'] ?? ''));

        if ($message === '' && $attach === '') {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้พิมพ์ข้อความ'], 422);
        }

        $id = bin2hex(random_bytes(8));
        $prompts = $_SESSION['ai_chat_prompts'] ?? [];
        $prompts[$id] = [
            'message' => mb_substr($message, 0, self::MAX_MESSAGE),
            'attach' => $attach,
        ];

        // เก็บไว้แค่ 3 รายการล่าสุด กันเซสชันบวมเมื่อครูพิมพ์ยาวหลายรอบ
        $_SESSION['ai_chat_prompts'] = array_slice($prompts, -3, 3, true);

        return $this->json($response, ['ok' => true, 'id' => $id]);
    }

    /**
     * รับไฟล์แนบ ดึงข้อความออกมาเก็บไว้ในเซสชัน แล้วคืนสรุปให้หน้าจอแสดงเป็นชิป
     * (เก็บไว้แค่ 3 ไฟล์ล่าสุดต่อผู้ใช้ เพื่อไม่ให้เซสชันบวม)
     */
    public function attach(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $file = ($request->getUploadedFiles()['file'] ?? null);
        if (!$file instanceof UploadedFileInterface) {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้เลือกไฟล์'], 422);
        }

        try {
            $text = TextExtract::fromUpload($file);
        } catch (RuntimeException $e) {
            return $this->json($response, ['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $id = bin2hex(random_bytes(8));
        $name = mb_substr((string) $file->getClientFilename(), 0, 120);

        $_SESSION['ai_chat_files'] = array_slice(
            ($_SESSION['ai_chat_files'] ?? []) + [],
            -2,
            2,
            true
        ) + [$id => ['name' => $name, 'text' => $text]];

        return $this->json($response, [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'chars' => mb_strlen($text),
            'preview' => mb_substr($text, 0, 160),
        ]);
    }

    /** ครูกดยืนยันจากการ์ดในช่องสนทนา แล้วระบบจึงสร้างรายวิชาให้จริง */
    public function createCourse(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return $this->json($response, ['ok' => false, 'message' => 'ข้อมูลรายวิชาไม่ครบ'], 422);
        }

        $termId = $this->courses->currentTermId();
        $classroomId = ((int) ($data['classroom_id'] ?? 0)) ?: null;

        if ($this->courses->codeTaken((int) $user['id'], $code, $termId, $classroomId, null)) {
            return $this->json($response, [
                'ok' => false,
                'message' => $classroomId === null
                    ? 'คุณมีรายวิชารหัส ' . $code . ' ในภาคเรียนนี้อยู่แล้ว — แก้รหัสวิชา หรือเลือกกลุ่มเรียนให้ต่างจากเดิมแล้วลองอีกครั้ง'
                    : 'คุณมีรายวิชารหัส ' . $code . ' ของกลุ่มเรียนนี้อยู่แล้ว — แก้รหัสวิชาหรือเปลี่ยนกลุ่มเรียนแล้วลองอีกครั้ง',
                'suggestion' => $this->freeCode((int) $user['id'], $code, $termId, $classroomId),
            ], 409);
        }

        $id = $this->courses->create((int) $user['id'], [
            'code' => $code,
            'name' => $name,
            'credits' => max(0, min(9.9, (float) ($data['credits'] ?? 3))),
            'theory_hours' => max(0, min(255, (int) ($data['theory_hours'] ?? 1))),
            'practice_hours' => max(0, min(255, (int) ($data['practice_hours'] ?? 2))),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'objectives' => trim((string) ($data['objectives'] ?? '')) ?: null,
            'competencies' => trim((string) ($data['competencies'] ?? '')) ?: null,
            'term_id' => $termId,
            'classroom_id' => $classroomId,
        ]);

        $this->auth->log('ai.chat.create_course', 'course#' . $id, ['code' => $code]);

        return $this->json($response, [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'url' => Url::to('/courses/' . $id),
            'editUrl' => Url::to('/courses/' . $id . '/edit'),
            'message' => 'สร้างรายวิชา ' . $name . ' แล้ว',
        ]);
    }

    /** หารหัสวิชาที่ยังว่างให้ครูใช้แทน เช่น 30202-2001 ชนแล้วเสนอ 30202-2002 ต่อไปเรื่อย ๆ */
    private function freeCode(int $teacherId, string $code, ?int $termId, ?int $classroomId): ?string
    {
        if (preg_match('/^(.*?)(\d+)$/u', $code, $m) !== 1) {
            return null;
        }

        $width = strlen($m[2]);
        for ($i = 1; $i <= 20; $i++) {
            $candidate = $m[1] . str_pad((string) ((int) $m[2] + $i), $width, '0', STR_PAD_LEFT);
            if (!$this->courses->codeTaken($teacherId, $candidate, $termId, $classroomId, null)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * สร้างหน่วยการเรียนพร้อมเนื้อหาที่ผู้ช่วยร่างไว้ — บันทึกเป็นฉบับร่างเสมอ
     * ครูตรวจแก้ในหน้าแก้ไขหน่วยแล้วค่อยกดเผยแพร่เอง
     */
    public function createUnit(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $courseId = (int) ($data['course_id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '' || $courseId <= 0) {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้เลือกรายวิชาหรือยังไม่มีชื่อหน่วย'], 422);
        }

        if (!$this->courses->ownedByTeacher($courseId, (int) $user['id'])) {
            return $this->json($response, ['ok' => false, 'message' => 'ไม่พบรายวิชานี้ในรายวิชาของคุณ'], 403);
        }

        $sections = json_decode((string) ($data['sections'] ?? '[]'), true);
        $sections = is_array($sections) ? $this->cleanSections($sections) : [];

        $unitId = $this->units->create([
            'course_id' => $courseId,
            'title' => mb_substr($title, 0, 191),
            'key_content' => trim((string) ($data['key_content'] ?? '')) ?: null,
            'objectives' => trim((string) ($data['objectives'] ?? '')) ?: null,
            'competencies' => trim((string) ($data['competencies'] ?? '')) ?: null,
            'sort_order' => $this->units->nextSortOrder($courseId),
            'source' => 'ai',
            'review_status' => 'draft',
            'created_by' => (int) $user['id'],
        ]);

        foreach ($sections as $i => $section) {
            $this->units->createSection([
                'unit_id' => $unitId,
                'type' => 'text',
                'title' => $section['title'],
                'content' => $section['content'],
                'sort_order' => $i + 1,
            ]);
        }

        $this->auth->log('ai.chat.create_unit', 'unit#' . $unitId, [
            'course_id' => $courseId,
            'sections' => count($sections),
        ]);

        return $this->json($response, [
            'ok' => true,
            'id' => $unitId,
            'url' => Url::to('/courses/' . $courseId . '/units/' . $unitId . '/edit'),
            'message' => 'ร่างหน่วยการเรียน ' . $title . ' พร้อมเนื้อหา ' . count($sections) . ' หัวข้อแล้ว',
        ]);
    }

    /**
     * ดึงข้อความที่ prepare() เก็บไว้ออกมาใช้ แล้วลบทิ้งทันที (ใช้ได้ครั้งเดียว)
     *
     * @return array{message:string,attach:string}
     */
    private function pullPrompt(string $id): array
    {
        $id = trim($id);
        $stored = $_SESSION['ai_chat_prompts'][$id] ?? null;

        if ($id === '' || !is_array($stored)) {
            return ['message' => '', 'attach' => ''];
        }

        unset($_SESSION['ai_chat_prompts'][$id]);

        return [
            'message' => (string) ($stored['message'] ?? ''),
            'attach' => (string) ($stored['attach'] ?? ''),
        ];
    }

    /** ข้อความจากไฟล์แนบที่เก็บไว้ในเซสชัน — รับได้หลายไฟล์ คั่นด้วยจุลภาค */
    private function attachedText(string $ids): string
    {
        $files = $_SESSION['ai_chat_files'] ?? [];
        if ($ids === '' || $files === []) {
            return '';
        }

        $parts = [];
        foreach (array_slice(explode(',', $ids), 0, 3) as $id) {
            $file = $files[trim($id)] ?? null;
            if ($file !== null) {
                $parts[] = sprintf("เนื้อหาจากไฟล์ \"%s\" ที่ครูแนบมา:\n%s", $file['name'], $file['text']);
            }
        }

        return implode("\n\n", $parts);
    }

    /** คำสั่งระบบของช่องสนทนา — ครูเท่านั้นที่สั่งให้สร้างรายวิชาได้ */
    private function systemPrompt(bool $isTeacher): string
    {
        $base = 'คุณเป็นผู้ช่วยครูอาชีวศึกษาในระบบ RVC Learn ตอบสั้น กระชับ เป็นภาษาไทย';

        if (!$isTeacher) {
            return $base;
        }

        return $base . "\n"
            . 'ถ้าครูแนบไฟล์มา ให้ใช้ข้อมูลในไฟล์นั้นตอบคำถามหรือเติมข้อมูลรายวิชาให้ครบที่สุด' . "\n"
            . 'ถ้าครูขอให้สร้างหรือเพิ่มรายวิชา ให้ตอบกลับเป็น JSON บรรทัดเดียวเท่านั้น '
            . 'ห้ามมีข้อความอื่นนำหน้าหรือต่อท้าย และห้ามครอบด้วย ``` ตามรูปแบบนี้ '
            . '{"action":"create_course","code":"20127-2002","name":"ชื่อวิชา","credits":3,"theory_hours":1,"practice_hours":2,"description":"คำอธิบายรายวิชา",'
            . '"objectives":["จุดประสงค์รายวิชาข้อ 1","ข้อ 2"],"competencies":["สมรรถนะรายวิชาข้อ 1","ข้อ 2"]}' . "\n"
            . 'objectives คือ "จุดประสงค์รายวิชา" และ competencies คือ "สมรรถนะรายวิชา" ใส่เป็น array ข้อละหนึ่งรายการ ไม่ต้องใส่เลขข้อ '
            . 'ถ้าครูให้มา ให้คัดลอกครบทุกข้อตามต้นฉบับ ถ้าไม่ได้ให้มาให้ใส่ array ว่าง' . "\n"
            . 'ถ้าครูให้คำอธิบายรายวิชามา (พิมพ์มาหรืออยู่ในไฟล์แนบ) ให้คัดลอกลง description ทั้งหมดทุกคำตามต้นฉบับ '
            . 'ห้ามย่อ ห้ามสรุป ห้ามตัดทอน ห้ามเรียบเรียงใหม่ — แต่งคำอธิบายเองได้เฉพาะเมื่อครูไม่ได้ให้มาเท่านั้น '
            . 'รหัสวิชา ชื่อวิชา หน่วยกิต และชั่วโมงทฤษฎี/ปฏิบัติ (ท-ป-น) ก็ให้ใช้ตามที่ครูให้มาเช่นกัน' . "\n"
            . 'ถ้าครูขอให้สร้างหน่วยการเรียน บทเรียน หรือเนื้อหาในรายวิชา ให้ตอบกลับเป็น JSON บรรทัดเดียว '
            . 'ในรูปแบบนี้แทน '
            . '{"action":"create_unit","course_code":"รหัสวิชาถ้าครูระบุมา","title":"ชื่อหน่วยการเรียน",'
            . '"key_content":"สาระสำคัญ 2-4 ประโยค","objectives":"จุดประสงค์การเรียนรู้ ขึ้นบรรทัดใหม่ด้วย \\n ข้อละบรรทัด",'
            . '"competencies":"สมรรถนะประจำหน่วย","sections":[{"title":"ชื่อหัวข้อย่อย","content":"เนื้อหาของหัวข้อนั้นอย่างละเอียด"}]} '
            . 'ให้มีหัวข้อย่อยใน sections 3-6 หัวข้อ เนื้อหาแต่ละหัวข้อยาวพอสอนได้จริง '
            . 'ถ้าครูไม่ได้บอกรหัสวิชา ให้ใส่ course_code เป็นค่าว่าง แล้วครูจะเลือกรายวิชาเองบนหน้าจอ' . "\n"
            . 'ถ้าครูไม่ได้บอกรหัสวิชาตอนสร้างรายวิชาใหม่ ให้ตั้งรหัสที่สมเหตุสมผลตามรูปแบบรหัสวิชาอาชีวศึกษา '
            . 'คำขออื่นนอกจากนี้ให้ตอบเป็นข้อความปกติ ห้ามตอบเป็น JSON';
    }

    /**
     * แปลงคำตอบที่เป็น JSON ให้เป็นคำสั่งที่หน้าจอเอาไปทำการ์ดยืนยัน
     *
     * @return array<string,mixed>|null
     */
    private function parseAction(string $raw, bool $isTeacher, int $teacherId = 0): ?array
    {
        if (!$isTeacher) {
            return null;
        }

        foreach (JsonStream::objects([$raw]) as $obj) {
            $action = (string) ($obj['action'] ?? '');

            if ($action === 'create_course') {
                $code = trim((string) ($obj['code'] ?? ''));
                $name = trim((string) ($obj['name'] ?? ''));
                if ($code === '' || $name === '') {
                    return null;
                }

                return [
                    'action' => 'create_course',
                    'code' => mb_substr($code, 0, 32),
                    'name' => mb_substr($name, 0, 191),
                    'credits' => (float) ($obj['credits'] ?? 3),
                    'theory_hours' => (int) ($obj['theory_hours'] ?? 1),
                    'practice_hours' => (int) ($obj['practice_hours'] ?? 2),
                    'description' => mb_substr(trim((string) ($obj['description'] ?? '')), 0, self::MAX_DESCRIPTION),
                    'objectives' => self::listText($obj['objectives'] ?? ''),
                    'competencies' => self::listText($obj['competencies'] ?? ''),
                    // ครูแก้รหัส/ชื่อ และเลือกกลุ่มเรียนได้บนการ์ดก่อนกดสร้าง
                    'classrooms' => array_map(static fn (array $c): array => [
                        'id' => (int) $c['id'],
                        'name' => (string) $c['name'],
                    ], $this->courses->classrooms()),
                ];
            }

            if ($action === 'create_unit') {
                $title = trim((string) ($obj['title'] ?? ''));
                if ($title === '') {
                    return null;
                }

                // ส่งรายวิชาของครูไปกับการ์ดด้วย ครูจะได้เลือกได้เองว่าจะลงหน่วยนี้ในวิชาไหน
                $courses = array_map(static fn (array $c): array => [
                    'id' => (int) $c['id'],
                    'code' => (string) $c['code'],
                    'name' => (string) $c['name'],
                ], $this->courses->forTeacher($teacherId));

                $wanted = trim((string) ($obj['course_code'] ?? ''));
                $match = null;
                foreach ($courses as $c) {
                    if ($wanted !== '' && $c['code'] === $wanted) {
                        $match = $c['id'];

                        break;
                    }
                }

                return [
                    'action' => 'create_unit',
                    'title' => mb_substr($title, 0, 191),
                    'key_content' => mb_substr(trim((string) ($obj['key_content'] ?? '')), 0, 2000),
                    'objectives' => mb_substr(trim((string) ($obj['objectives'] ?? '')), 0, 2000),
                    'competencies' => mb_substr(trim((string) ($obj['competencies'] ?? '')), 0, 2000),
                    'sections' => $this->cleanSections($obj['sections'] ?? []),
                    'courses' => $courses,
                    'course_id' => $match ?? ($courses[0]['id'] ?? null),
                ];
            }
        }

        return null;
    }

    /**
     * รายการข้อ ๆ จาก AI (array หรือข้อความหลายบรรทัด) → ข้อความข้อละบรรทัด ตัดเลขข้อนำหน้าออก
     */
    private static function listText(mixed $raw): string
    {
        $items = is_array($raw) ? $raw : preg_split('/\R+/u', (string) $raw);
        $out = [];
        foreach ($items ?: [] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $line = trim((string) preg_replace('/^\s*(?:[-•*]|\d+[.)]|[๑-๙]+[.)])\s*/u', '', (string) $item));
            if ($line !== '') {
                $out[] = mb_substr($line, 0, 1000);
            }
        }

        return implode("\n", array_slice($out, 0, 30));
    }

    /**
     * หัวข้อย่อยที่โมเดลร่างมา — รับเฉพาะแบบข้อความ และจำกัดจำนวน/ความยาวไว้กันข้อมูลบวม
     *
     * @return list<array{title:string,content:string}>
     */
    private function cleanSections(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $sections = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $content = trim((string) ($item['content'] ?? ''));
            if ($title === '' && $content === '') {
                continue;
            }

            $sections[] = [
                'title' => mb_substr($title === '' ? 'หัวข้อย่อย' : $title, 0, 191),
                'content' => mb_substr($content, 0, 20000),
            ];

            if (count($sections) >= 12) {
                break;
            }
        }

        return $sections;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
