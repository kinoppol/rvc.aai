<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiRouter;
use App\AI\AiUnavailableException;
use App\AI\GoogleAiProvider;
use App\AI\KeyCipher;
use App\AI\ModelRoles;
use App\AI\OpenAiCompatibleProvider;
use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SensitiveParameter;

/**
 * ตั้งค่า → ผู้ช่วย AI ของฉัน: การ์ดเทียบ AI ส่วนกลาง / AI ของฉัน, ตัวช่วยเชื่อม API 3 ขั้น, โหมดคุณภาพ
 */
final class SettingsController
{
    private const PROVIDERS = [
        'google' => 'Google AI Studio',
        'openrouter' => 'OpenRouter',
        'thaillm' => 'ThaiLLM',
        'tokenme' => '9ARM (tokenme.limited)',
        'openai_compatible' => 'บริการอื่นที่กรอกที่อยู่เองได้',
    ];

    public function __construct(
        private readonly View $view,
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
        private readonly KeyCipher $cipher,
        private readonly Auth $auth,
        private readonly AiRouter $router,
        private readonly ModelRoles $modelRoles,
    ) {
    }

    public function ai(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $uid = (int) $user['id'];

        $period = sprintf('%04d-%02d', $this->settings->int('academic_year'), (int) date('n'));
        $quota = $this->ai->quota($uid, $period, $this->settings->int('ai_monthly_quota', 60));
        $endpoint = $this->ai->defaultEndpoint();
        $key = $this->ai->activeKeyForUser($uid);

        // คีย์ของครูทั้งหมด พร้อมโมเดลที่เปิดใช้ของแต่ละคีย์
        $keys = array_map(fn (array $k): array => $k + [
            'provider_name' => self::PROVIDERS[$k['provider']] ?? $k['provider'],
            'display_name' => AiRouter::keyName($k),
            'nickname' => self::nickname($k),
            'models' => array_values(array_filter(AiRepository::enabledModels($k))),
            'roles' => $this->modelRoles->forModels('k' . $k['id'], array_values(array_filter(AiRepository::enabledModels($k)))),
            'verified_ago' => $k['verified_at'] ? Thai::ago($k['verified_at']) : null,
        ], $this->ai->activeKeysForUser($uid));

        return $this->view->render($response, 'settings/ai', [
            'page' => 'settings',
            'quota' => $quota,
            'endpoint' => $endpoint,
            'queue' => $endpoint ? max((int) $endpoint['queue_length'], $this->ai->queueLength()) : 0,
            'byokAllowed' => $this->settings->bool('allow_teacher_byok', true),
            'key' => $key,
            'keyProviderName' => $key ? (self::PROVIDERS[$key['provider']] ?? $key['provider']) : null,
            'keyVerifiedAgo' => $key && $key['verified_at'] ? Thai::ago($key['verified_at']) : null,
            'qualityPref' => $this->settings->userQualityPref($uid),
            'routePref' => $this->settings->userRoutePref($uid),
            'providers' => self::PROVIDERS,
            'keys' => $keys,
            'aiOptions' => $this->router->options($uid),
            'aiDefault' => $this->router->defaultTarget($uid),
        ]);
    }

    public function test(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $provider = (string) ($data['provider'] ?? '');
        $key = trim((string) ($data['key'] ?? ''));
        $baseUrl = self::baseUrlFor($provider, $data);

        return $this->json($response, $this->probeKey($provider, $key, $baseUrl));
    }

    public function connect(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/settings/ai');
        }

        $provider = array_key_exists((string) ($data['provider'] ?? ''), self::PROVIDERS) ? (string) $data['provider'] : 'google';
        $key = trim((string) ($data['key'] ?? ''));
        $baseUrl = self::baseUrlFor($provider, $data);

        $probe = $this->probeKey($provider, $key, $baseUrl);
        if (!$probe['ok']) {
            Flash::error($probe['message']);

            return $this->redirect($response, '/settings/ai');
        }

        // โมเดลที่ครูติ๊กไว้ในขั้นสุดท้าย — ไม่ได้ติ๊กเลยใช้โมเดลที่ผู้ให้บริการแนะนำ
        $chosen = array_values((array) ($data['models'] ?? []));
        $models = AiRepository::modelFields($chosen !== [] ? $chosen : [(string) ($probe['model'] ?? '')]);

        $keyId = $this->ai->saveKey((int) $user['id'], $models + [
            'provider' => $provider,
            'label' => self::cleanLabel((string) ($data['label'] ?? '')) ?: self::PROVIDERS[$provider],
            'base_url' => $baseUrl,
            'key_encrypted' => $this->cipher->encrypt($key),
            'key_last4' => $this->cipher->last4($key),
            'status' => 'active',
            'verified_at' => date('Y-m-d H:i:s'),
        ]);

        $this->modelRoles->savePosted('k' . $keyId, (string) ($data['roles_json'] ?? ''), AiRepository::enabledModels($models));
        $this->auth->log('ai.key.connect', $provider);
        Flash::success('เชื่อม AI ของฉันแล้ว · ' . self::PROVIDERS[$provider]);

        return $this->redirect($response, '/settings/ai');
    }

    public function disconnect(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        // ยกเลิกทีละคีย์ — ไม่ระบุคีย์ = ยกเลิกทั้งหมด (ปุ่มเดิม)
        $keyId = (int) ($data['key_id'] ?? 0);
        if ($keyId > 0) {
            $this->ai->disableKey((int) $user['id'], $keyId);
        } else {
            $this->ai->disconnectUser((int) $user['id']);
        }
        $this->auth->log('ai.key.disconnect', $keyId > 0 ? 'key#' . $keyId : null);
        Flash::success($this->ai->activeKeysForUser((int) $user['id']) === []
            ? 'ยกเลิกการเชื่อมแล้ว · กลับไปใช้ AI ของส่วนกลาง'
            : 'ยกเลิกการเชื่อมบริการนี้แล้ว');

        return $this->redirect($response, '/settings/ai');
    }

    public function mode(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        $pref = ($data['mode'] ?? '') === 'quality' ? 'quality' : 'fast';
        $this->settings->set('ai_quality:' . (int) $user['id'], $pref, 'string', 'ai');
        Flash::success('บันทึกโหมดคุณภาพของผลลัพธ์แล้ว');

        return $this->redirect($response, '/settings/ai');
    }

    /** ครูเลือกว่าจะให้ผู้ช่วยใช้แหล่งไหนก่อน เมื่อเชื่อมไว้มากกว่าหนึ่งแหล่ง */
    public function route(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        $pref = ($data['route'] ?? '') === 'college' ? 'college' : 'byok';
        $this->settings->set('ai_route:' . (int) $user['id'], $pref, 'string', 'ai');
        $this->auth->log('ai.route.prefer', $pref);
        Flash::success($pref === 'college'
            ? 'ใช้ AI ของส่วนกลางก่อน · จะสลับไปใช้ AI ของครูให้อัตโนมัติเมื่อเครื่องส่วนกลางไม่ว่าง'
            : 'ใช้ AI ของฉันก่อน · จะสลับไปใช้เครื่องของส่วนกลางให้อัตโนมัติเมื่อ AI ของครูใช้ไม่ได้');

        return $this->redirect($response, '/settings/ai');
    }

    /** ครูเลือกโมเดลที่เปิดใช้ของคีย์หนึ่ง */
    public function keyModels(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        $key = $this->ai->keyForUser((int) $user['id'], (int) $args['id']);
        if ($key === null) {
            Flash::error('ไม่พบการเชื่อมต่อนี้');

            return $this->redirect($response, '/settings/ai');
        }

        $models = AiRepository::modelFields(array_values((array) ($data['models'] ?? [])));
        $this->ai->updateKey((int) $user['id'], (int) $key['id'], $models);
        $this->modelRoles->savePosted('k' . $key['id'], (string) ($data['roles_json'] ?? ''), AiRepository::enabledModels($models));
        $this->auth->log('ai.key.models', 'key#' . $key['id']);
        Flash::success('บันทึกโมเดลและบทบาทแล้ว');

        return $this->redirect($response, '/settings/ai');
    }

    /** ดึงรายชื่อโมเดลของคีย์ที่บันทึกไว้แล้ว (ใช้รหัสที่เก็บไว้ ครูไม่ต้องวางใหม่) */
    public function keyProbe(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $key = $this->ai->keyForUser((int) $user['id'], (int) $args['id']);
        if ($key === null) {
            return $this->json($response, ['ok' => false, 'message' => 'ไม่พบการเชื่อมต่อนี้'], 404);
        }

        try {
            $secret = $this->cipher->decrypt((string) $key['key_encrypted']);
        } catch (\RuntimeException) {
            return $this->json($response, ['ok' => false, 'message' => 'อ่านรหัสที่เก็บไว้ไม่ได้ กรุณาเชื่อมต่อใหม่']);
        }

        return $this->json($response, $this->probeKey((string) $key['provider'], $secret, $key['base_url'] ?: null));
    }

    /**
     * แก้ไขบริการที่เชื่อมไว้: ชื่อเล่น, ที่อยู่ (เฉพาะบริการที่กรอกเอง) และรหัสใหม่ (เว้นว่าง = ใช้รหัสเดิม)
     * เปลี่ยนรหัสหรือที่อยู่เมื่อไรจะทดสอบกับผู้ให้บริการก่อน ไม่ผ่านจะไม่บันทึกอะไรเลย
     */
    public function keyEdit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        $key = $this->ai->keyForUser((int) $user['id'], (int) $args['id']);
        if ($key === null) {
            Flash::error('ไม่พบการเชื่อมต่อนี้');

            return $this->redirect($response, '/settings/ai');
        }

        $provider = (string) $key['provider'];
        $label = self::cleanLabel((string) ($data['label'] ?? ''));
        $fields = ['label' => $label !== '' ? $label : (self::PROVIDERS[$provider] ?? $provider)];

        $baseUrl = $key['base_url'] ?: null;
        if ($provider === 'openai_compatible') {
            $baseUrl = trim((string) ($data['base_url'] ?? '')) ?: null;
            if ($baseUrl === null) {
                Flash::error('กรุณากรอกที่อยู่ของบริการ');

                return $this->redirect($response, '/settings/ai');
            }
        }

        $newKey = trim((string) ($data['key'] ?? ''));
        if ($newKey !== '' || $baseUrl !== ($key['base_url'] ?: null)) {
            try {
                $secret = $newKey !== '' ? $newKey : $this->cipher->decrypt((string) $key['key_encrypted']);
            } catch (\RuntimeException) {
                Flash::error('อ่านรหัสที่เก็บไว้ไม่ได้ กรุณาวางรหัสใหม่');

                return $this->redirect($response, '/settings/ai');
            }

            $probe = $this->probeKey($provider, $secret, $baseUrl);
            if (!$probe['ok']) {
                Flash::error('ยังไม่ได้บันทึก · ' . $probe['message']);

                return $this->redirect($response, '/settings/ai');
            }

            $fields += ['base_url' => $baseUrl, 'verified_at' => date('Y-m-d H:i:s')];
            if ($newKey !== '') {
                $fields += ['key_encrypted' => $this->cipher->encrypt($newKey), 'key_last4' => $this->cipher->last4($newKey)];
            }
        }

        $this->ai->updateKey((int) $user['id'], (int) $key['id'], $fields);
        $this->auth->log('ai.key.edit', 'key#' . $key['id'], ['new_key' => $newKey !== '']);
        Flash::success('บันทึกการเชื่อมต่อ ' . AiRouter::keyName($fields + $key) . ' แล้ว');

        return $this->redirect($response, '/settings/ai');
    }

    /** AI ที่ใช้เป็นค่าเริ่มต้นของครู ('' = ให้ระบบเลือกตามลำดับ) */
    public function defaultAi(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $uid = (int) $user['id'];
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        $target = trim((string) ($data['target'] ?? ''));
        $option = null;
        foreach ($this->router->options($uid) as $o) {
            if ($o['id'] === $target) {
                $option = $o;
            }
        }

        if ($target !== '' && $option === null) {
            Flash::error('ไม่พบ AI ที่เลือก อาจถูกปิดไปแล้ว');

            return $this->redirect($response, '/settings/ai');
        }

        $this->settings->set('ai_target:' . $uid, $target, 'string', 'ai');
        // ให้ลำดับสำรองไปทางแหล่งเดียวกับตัวที่เลือก
        if ($option !== null) {
            $this->settings->set('ai_route:' . $uid, $option['source'], 'string', 'ai');
        }
        $this->auth->log('ai.default.set', $target !== '' ? $target : 'auto');
        Flash::success($option !== null
            ? 'ตั้งค่าเริ่มต้นเป็น ' . $option['group'] . ' · ' . $option['label'] . ' แล้ว'
            : 'ให้ระบบเลือก AI ให้อัตโนมัติแล้ว');

        return $this->redirect($response, '/settings/ai');
    }

    /**
     * ทดสอบรหัสกับบริการจริง (ขอรายชื่อโมเดล) แล้วคืนชื่อโมเดลที่จะใช้เรียกงานต่อไป
     *
     * @return array{ok:bool,message:string,model?:string,models?:list<string>}
     */
    private function probeKey(string $provider, #[SensitiveParameter] string $key, ?string $baseUrl = null): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'message' => 'ยังไม่ได้วางรหัส กรุณาวางรหัสที่คัดลอกมาจากผู้ให้บริการ'];
        }
        if (str_contains($key, ' ') || str_contains($key, "\n")) {
            return ['ok' => false, 'message' => 'รหัสมีช่องว่างปน กรุณาคัดลอกทั้งบรรทัด ไม่เว้นวรรคหน้า–หลัง'];
        }

        try {
            return match ($provider) {
                'google' => GoogleAiProvider::probe($key),
                'openrouter' => OpenAiCompatibleProvider::probe(
                    $key,
                    OpenAiCompatibleProvider::OPENROUTER_BASE,
                    OpenAiCompatibleProvider::OPENROUTER_DEFAULT_MODEL,
                    verifyKey: true
                ),
                default => OpenAiCompatibleProvider::probe($key, (string) $baseUrl),
            };
        } catch (AiUnavailableException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** ชื่อเล่นที่ครูตั้ง — ว่างถ้ายังใช้ชื่อผู้ให้บริการอยู่ */
    private static function nickname(array $key): string
    {
        $label = trim((string) ($key['label'] ?? ''));

        return $label === (self::PROVIDERS[$key['provider']] ?? $key['provider']) ? '' : $label;
    }

    /** ชื่อเล่นบรรทัดเดียว ยาวไม่เกิน 60 ตัวอักษร */
    private static function cleanLabel(string $label): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $label)), 0, 60);
    }

    /** ผู้ให้บริการที่รู้ที่อยู่อยู่แล้ว (ThaiLLM, 9ARM) ไม่ต้องให้ครูกรอกเอง */
    private static function baseUrlFor(string $provider, array $data): ?string
    {
        if ($provider === 'thaillm') {
            return OpenAiCompatibleProvider::THAILLM_BASE;
        }
        if ($provider === 'tokenme') {
            return OpenAiCompatibleProvider::TOKENME_BASE;
        }

        return trim((string) ($data['base_url'] ?? '')) ?: null;
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
