<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * หน้าตั้งรหัสผ่านใหม่จากลิงก์ที่ผู้ดูแลระบบสร้างให้ (กรณีลืมรหัสผ่าน) — ไม่ต้องล็อกอินก่อน
 */
final class ResetPasswordController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        $user = $this->auth->userForPasswordResetToken($token);

        if ($user === null) {
            Flash::error('ลิงก์รีเซ็ตรหัสผ่านนี้หมดอายุหรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่จากผู้ดูแลระบบ');

            return $response->withHeader('Location', Url::to('/login'))->withStatus(302);
        }

        return $this->view->render($response, 'auth/reset-password', [
            'token' => $token,
            'fullName' => $user['full_name'],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        $data = (array) $request->getParsedBody();

        $user = $this->auth->userForPasswordResetToken($token);
        if ($user === null) {
            Flash::error('ลิงก์รีเซ็ตรหัสผ่านนี้หมดอายุหรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่จากผู้ดูแลระบบ');

            return $response->withHeader('Location', Url::to('/login'))->withStatus(302);
        }

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $response->withHeader('Location', Url::to('/reset-password/' . $token))->withStatus(302);
        }

        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirm'] ?? '');

        if (mb_strlen($password) < 8) {
            Flash::error('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');

            return $response->withHeader('Location', Url::to('/reset-password/' . $token))->withStatus(302);
        }

        if ($password !== $confirm) {
            Flash::error('รหัสผ่านทั้งสองช่องไม่ตรงกัน');

            return $response->withHeader('Location', Url::to('/reset-password/' . $token))->withStatus(302);
        }

        $this->auth->resetPasswordWithToken($token, $password);
        Flash::success('ตั้งรหัสผ่านใหม่เรียบร้อย เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย');

        return $response->withHeader('Location', Url::to('/login'))->withStatus(302);
    }
}
