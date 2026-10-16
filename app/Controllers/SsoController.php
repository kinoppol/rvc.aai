<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\LoginMethods;
use App\Auth\RvcOne;
use App\Auth\RvcOneException;
use App\Support\Flash;
use App\Support\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * เข้าสู่ระบบผ่าน RVC-ONE (SSO ของวิทยาลัย)
 *
 * GET  /auth/login   สร้าง state เก็บใน session แล้วพาไปหน้าเข้าสู่ระบบของ RVC-ONE
 *                     (ใช้เป็น "URL เริ่ม SSO ของแอป" ในหน้าแก้ไขแอปของ RVC-ONE ด้วย)
 * POST /auth/callback RVC-ONE ส่ง token_id / token_key / state กลับมา → ตรวจ state → verify ฝั่ง server → เปิด session
 * GET  /auth/callback ?error=access_denied เมื่อผู้ใช้กดไม่อนุญาต
 *
 * คุกกี้ session เป็น SameSite=Lax จึงไม่ติดมากับ POST ข้ามเว็บจาก RVC-ONE — index.php จึงไม่เปิด session
 * ให้คำขอนั้น และหน้านี้ส่งฟอร์มเดิมซ้ำมาที่ตัวเอง (relay) ซึ่งเป็นคำขอจากเว็บเดียวกัน คุกกี้จึงติดมา
 */
final class SsoController
{
    private const STATE_TTL = 600;

    public function __construct(
        private readonly Auth $auth,
        private readonly RvcOne $rvcOne,
        private readonly LoginMethods $methods,
        private readonly AuthController $login,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function start(Request $request, Response $response): Response
    {
        if (!$this->methods->rvcOne()) {
            Flash::error('ผู้ดูแลระบบปิดการเข้าสู่ระบบผ่าน RVC-ONE ไว้');

            return $this->redirect($response, '/login');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['sso_state'] = ['value' => $state, 'at' => time()];

        return $response->withHeader('Location', $this->rvcOne->authorizeUrl($state))->withStatus(302);
    }

    public function callback(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'GET') {
            unset($_SESSION['sso_state']);
            $error = (string) ($request->getQueryParams()['error'] ?? '');
            if ($error === 'access_denied') {
                Flash::warning('คุณยกเลิกการเข้าสู่ระบบผ่าน RVC-ONE');
            } elseif ($error !== '') {
                Flash::error('เข้าสู่ระบบผ่าน RVC-ONE ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            }

            return $this->redirect($response, '/login');
        }

        $data = (array) $request->getParsedBody();
        $tokenId = trim((string) ($data['token_id'] ?? ''));
        $tokenKey = trim((string) ($data['token_key'] ?? ''));
        $state = trim((string) ($data['state'] ?? ''));

        // POST ข้ามเว็บมาโดยไม่มีคุกกี้ session — ส่งต่อให้เบราว์เซอร์ POST ซ้ำจากเว็บนี้เอง
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $this->relay($response, $tokenId, $tokenKey, $state);
        }
        if (!$this->methods->rvcOne()) {
            unset($_SESSION['sso_state']);

            return $this->fail($response, 403, 'ผู้ดูแลระบบปิดการเข้าสู่ระบบผ่าน RVC-ONE ไว้');
        }

        // ตรวจ state กัน CSRF — ใช้ได้ครั้งเดียว
        $stored = $_SESSION['sso_state'] ?? null;
        unset($_SESSION['sso_state']);
        $storedValue = is_array($stored) && time() - (int) ($stored['at'] ?? 0) <= self::STATE_TTL ? (string) ($stored['value'] ?? '') : '';
        if ($storedValue !== '') {
            if (!hash_equals($storedValue, $state)) {
                return $this->fail($response, 400, 'ข้อมูลการเข้าสู่ระบบไม่ตรงกับคำขอเดิม (state ไม่ตรง) กรุณาเริ่มเข้าสู่ระบบผ่าน RVC-ONE ใหม่อีกครั้ง');
            }
        } elseif ($state !== '') {
            // มี state มาแต่ไม่มีของเราเก็บไว้ (หมดอายุ / เปิดคนละเบราว์เซอร์) — ไม่ใช่การเปิดจากหน้า Workspace
            return $this->fail($response, 400, 'คำขอเข้าสู่ระบบหมดอายุแล้ว กรุณากด "ลงชื่อเข้าใช้ผ่านระบบ RVC-ONE" ใหม่อีกครั้ง');
        }
        // ไม่มี state ทั้งสองฝั่ง = ผู้ใช้กดเปิดแอปจากหน้า Workspace ของ RVC-ONE — ด่านจริงคือการ verify ด้านล่าง

        if ($tokenId === '' || $tokenKey === '') {
            return $this->fail($response, 400, 'ไม่ได้รับข้อมูลยืนยันตัวตนจาก RVC-ONE กรุณาลองใหม่อีกครั้ง');
        }

        try {
            $ssoUser = $this->rvcOne->verify($tokenId, $tokenKey);
        } catch (RvcOneException $e) {
            // ห้ามใส่ token_id / token_key ลง log
            $this->logger->warning('RVC-ONE verify failed: ' . $e->reason, ['message' => $e->getMessage(), 'error' => $e->error]);

            return $this->fail($response, 401, $e->getMessage());
        }

        $ssoId = (string) ($ssoUser['id'] ?? '');
        $user = $this->auth->findBySso($ssoUser);
        if ($user === null) {
            $this->logger->notice('RVC-ONE login: no matching account', ['sso_user_id' => $ssoId]);

            return $this->fail($response, 401, sprintf(
                'ยืนยันตัวตนกับ RVC-ONE แล้ว แต่ไม่พบบัญชีของ %s ในระบบนี้ กรุณาติดต่อผู้ดูแลระบบ',
                trim((string) ($ssoUser['first_name'] ?? '') . ' ' . (string) ($ssoUser['last_name'] ?? '')) ?: 'คุณ'
            ));
        }
        if ($user['status'] !== 'active') {
            return $this->fail($response, 401, $user['status'] === 'pending'
                ? 'บัญชีนี้อยู่ระหว่างรอผู้ดูแลระบบอนุมัติ กรุณารอการติดต่อกลับ'
                : 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
        }

        $this->auth->start($user);
        $this->auth->log('login', null, ['via' => 'rvc-one', 'sso_user_id' => $ssoId]);

        $intended = $this->auth->pullIntended();

        return $intended !== null
            ? $response->withHeader('Location', $intended)->withStatus(302)
            : $this->redirect($response, '/dashboard');
    }

    /** หน้าเล็ก ๆ ที่ส่งฟอร์มเดิมกลับมาที่ callback อีกครั้งจากเว็บนี้เอง (ดูคำอธิบายบนคลาส) */
    private function relay(Response $response, string $tokenId, string $tokenKey, string $state): Response
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $action = $e(Url::to('/auth/callback'));
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex"><title>กำลังเข้าสู่ระบบ…</title>
            <style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.6 system-ui,sans-serif;color:#334155;background:#F5F3FF}</style>
            </head><body>
            <form id="relay" method="post" action="{$action}">
                <input type="hidden" name="token_id" value="{$e($tokenId)}">
                <input type="hidden" name="token_key" value="{$e($tokenKey)}">
                <input type="hidden" name="state" value="{$e($state)}">
                <input type="hidden" name="relayed" value="1">
                <p>กำลังเข้าสู่ระบบผ่าน RVC-ONE…</p>
                <noscript><button type="submit">ดำเนินการต่อ</button></noscript>
            </form>
            <script>document.getElementById('relay').submit();</script>
            </body></html>
            HTML;
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function fail(Response $response, int $status, string $message): Response
    {
        Flash::error($message);

        return $this->login->loginView($response)->withStatus($status);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
