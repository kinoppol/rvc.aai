<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Auth\LoginMethods;
use App\Domain\SettingsRepository;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ตั้งค่าทั่วไปของระบบ — ชื่อระบบและชื่อวิทยาลัยที่แสดงทั่วทั้งเว็บ และวิธีเข้าสู่ระบบที่เปิดให้ใช้
 */
final class GeneralController
{
    public function __construct(
        private readonly View $view,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
        private readonly LoginMethods $methods,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/settings', [
            'page' => 'admin-settings',
            'siteName' => (string) $this->settings->get('site_name', ''),
            'collegeName' => (string) $this->settings->get('college_name', ''),
            'loginPassword' => $this->methods->password(),
            'loginRvcOne' => $this->methods->rvcOne(),
            'rvcOneAvailable' => $this->methods->rvcOneAvailable(),
            // URL เต็มของทางเข้าฉุกเฉิน ให้ผู้ดูแลคัดลอกไปเก็บไว้ก่อนปิดการเข้าด้วยรหัสผ่าน
            'adminLoginUrl' => rtrim((string) $this->config->get('app.url'), '/') . '/login/admin',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $siteName = trim((string) ($data['site_name'] ?? ''));
        $collegeName = trim((string) ($data['college_name'] ?? ''));

        if ($siteName === '') {
            Flash::error('กรุณากรอกชื่อระบบ');

            return $this->redirect($response);
        }

        $this->settings->set('site_name', $siteName, 'string', 'general');
        $this->settings->set('college_name', $collegeName, 'string', 'general');
        $this->auth->log('settings.general.save', null, ['site_name' => $siteName, 'college_name' => $collegeName]);

        Flash::success('บันทึกชื่อระบบแล้ว');

        return $this->redirect($response);
    }

    public function saveLogin(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $password = ($data['login_password'] ?? '') === '1';
        $rvcOne = ($data['login_rvc_one'] ?? '') === '1';

        try {
            $this->methods->save($password, $rvcOne);
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());

            return $this->redirect($response);
        }

        $this->auth->log('settings.login_methods.save', null, ['password' => $password, 'rvc_one' => $rvcOne]);
        Flash::success('บันทึกวิธีเข้าสู่ระบบแล้ว');

        return $this->redirect($response);
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/settings'))->withStatus(302);
    }
}
