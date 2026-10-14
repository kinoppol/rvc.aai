<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\LoginMethods;
use App\Auth\Roles;
use App\Domain\InstitutionRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
        private readonly InstitutionRepository $institutions,
        private readonly LoginMethods $methods,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/dashboard');
        }

        return $this->loginView($response, [
            // มาจากลิงก์เข้าร่วมรายวิชา — เปิดแท็บนักเรียนไว้ให้เลย
            'as' => str_contains((string) ($_SESSION['intended'] ?? ''), '/join/') ? 'student' : 'staff',
        ], (string) ($request->getQueryParams()['admin'] ?? '') === '1');
    }

    /**
     * หน้าเข้าสู่ระบบเฉพาะผู้ดูแลระบบ (/login/admin) — ทางเข้าฉุกเฉินเมื่อเปิดเฉพาะ RVC-ONE แล้ว RVC-ONE ใช้งานไม่ได้
     * ไม่พึ่ง RVC-ONE เลย รับเฉพาะบัญชีบทบาทผู้ดูแลระบบ · ถ้ายังเปิดการเข้าด้วยรหัสผ่านอยู่ก็ส่งไปหน้าปกติ
     */
    public function adminLogin(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/dashboard');
        }
        if ($this->methods->password()) {
            return $this->redirect($response, '/login');
        }

        return $this->loginView($response, [], true);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        // ผู้ดูแลปิดการเข้าด้วยรหัสผ่านไว้ = เหลือเฉพาะทางเข้าของผู้ดูแลระบบ
        $adminOnly = !$this->methods->password();
        $as = !$adminOnly && (string) ($data['as'] ?? 'staff') === 'student' ? 'student' : 'staff';
        $institutionId = ((int) ($data['institution_id'] ?? 0)) ?: null;

        $formState = [
            'username' => $username,
            'as' => $as,
            'institution_id' => (string) ($data['institution_id'] ?? ''),
        ];

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, $adminOnly ? '/login/admin' : '/login');
        }

        if ($username === '' || $password === '' || ($as === 'student' && $institutionId === null)) {
            Flash::error($as === 'student'
                ? 'กรุณาเลือกสถานศึกษา และกรอกรหัสนักศึกษา/รหัสผ่านให้ครบ'
                : 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน');

            return $this->loginView($response, $formState, $adminOnly);
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $result = $this->auth->attempt(
            $username,
            $password,
            $ip,
            $as === 'student' ? $institutionId : null,
            $adminOnly ? [Roles::ADMIN] : []
        );

        if (!$result['ok']) {
            Flash::error($result['message']);

            return $this->loginView($response, $formState, $adminOnly);
        }

        $this->auth->log('login');

        // กลับไปหน้าที่ตั้งใจเปิดก่อนถูกพามาเข้าสู่ระบบ
        $intended = $this->auth->pullIntended();
        if ($intended !== null) {
            return $response->withHeader('Location', $intended)->withStatus(302);
        }

        return $this->redirect($response, '/dashboard');
    }

    public function logout(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->redirect($response, '/dashboard');
        }

        // ถ้ากำลังสวมสิทธิ์ผู้ใช้อื่นอยู่ การ "ออกจากระบบ" คือกลับไปเป็นผู้ดูแลเดิม
        if ($this->auth->isImpersonating()) {
            $leftUserId = (int) ($_SESSION['user_id'] ?? 0);
            $restored = $this->auth->stopImpersonating();

            if ($restored) {
                $this->auth->log('impersonate.stop', 'user#' . $leftUserId);
                Flash::success('กลับมาเป็นผู้ดูแลระบบแล้ว');

                return $this->redirect($response, '/admin/users');
            }

            Flash::error('บัญชีผู้ดูแลเดิมใช้งานไม่ได้ กรุณาเข้าสู่ระบบใหม่');

            return $this->redirect($response, '/login');
        }

        $this->auth->log('logout');
        $this->auth->logout();

        return $this->redirect($response, '/login');
    }

    /**
     * หน้าเข้าสู่ระบบตามวิธีที่ผู้ดูแลระบบเปิดไว้ — SsoController ใช้แสดงข้อผิดพลาดด้วย
     * $adminForm = แสดงฟอร์มรหัสผ่านของผู้ดูแลระบบ แม้ปิดการเข้าด้วยรหัสผ่านไว้ (/login?admin=1)
     *
     * @param array<string,mixed> $state
     */
    public function loginView(Response $response, array $state = [], bool $adminForm = false): Response
    {
        $password = $this->methods->password();

        return $this->view->render($response, 'auth/login', $state + [
            'username' => '',
            'as' => 'staff',
            'institution_id' => '',
            'institutions' => $this->institutions->all(),
            'sso' => $this->methods->rvcOne(),
            'passwordForm' => $password || $adminForm,
            'adminOnly' => !$password,
        ]);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
