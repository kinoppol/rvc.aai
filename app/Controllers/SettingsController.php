<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiUnavailableException;
use App\AI\GoogleAiProvider;
use App\AI\KeyCipher;
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
        'openai_compatible' => 'บริการอื่นที่กรอกที่อยู่เองได้',
    ];

    public function __construct(
        private readonly View $view,
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
        private readonly KeyCipher $cipher,
        private readonly Auth $auth,
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
        $baseUrl = trim((string) ($data['base_url'] ?? '')) ?: null;

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
        $baseUrl = trim((string) ($data['base_url'] ?? '')) ?: null;

        $probe = $this->probeKey($provider, $key, $baseUrl);
        if (!$probe['ok']) {
            Flash::error($probe['message']);

            return $this->redirect($response, '/settings/ai');
        }

        $this->ai->saveKey((int) $user['id'], [
            'provider' => $provider,
            'label' => self::PROVIDERS[$provider],
            'base_url' => $baseUrl,
            'model' => $probe['model'] ?? null,
            'key_encrypted' => $this->cipher->encrypt($key),
            'key_last4' => $this->cipher->last4($key),
            'status' => 'active',
            'verified_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auth->log('ai.key.connect', $provider);
        Flash::success('เชื่อม AI ของฉันแล้ว · ระบบจะใช้ของครูก่อนเสมอ');

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

        $this->ai->disconnectUser((int) $user['id']);
        $this->auth->log('ai.key.disconnect');
        Flash::success('ยกเลิกการเชื่อมแล้ว · กลับไปใช้ AI ของส่วนกลาง');

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
