<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Auth\LoginMethods;
use App\Auth\RvcOne;
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
        private readonly RvcOne $rvcOne,
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
            'rvcOneClientId' => $this->rvcOne->clientId(),
            'rvcOneSecret' => $this->rvcOne->secretStatus(),
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

    /** บันทึก client_secret ของ RVC-ONE (เข้ารหัสเก็บ) แล้วถาม RVC-ONE ทันทีว่ารับหรือไม่ · ช่องว่าง = คงค่าเดิม */
    public function saveRvcOne(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $secret = trim((string) ($data['client_secret'] ?? ''));
        $clear = ($data['clear_secret'] ?? '') === '1';
        if ($clear) {
            $this->rvcOne->saveSecret('');
            $this->auth->log('settings.rvc_one.secret_clear');
            Flash::success('ลบ client_secret ที่ตั้งไว้ในหน้านี้แล้ว');

            return $this->redirect($response);
        }
        if ($secret !== '') {
            if (strlen($secret) > 255 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
                Flash::error('client_secret ไม่ถูกต้อง');

                return $this->redirect($response);
            }
            $this->rvcOne->saveSecret($secret);
            $this->auth->log('settings.rvc_one.secret_save');
        } elseif ($this->rvcOne->secretStatus()['source'] === '') {
            Flash::error('กรุณากรอก client_secret');

            return $this->redirect($response);
        }

        match ($this->rvcOne->checkClient()) {
            true => Flash::success($secret !== '' ? 'บันทึกแล้ว — RVC-ONE ยอมรับรหัสแอปนี้' : 'RVC-ONE ยอมรับรหัสแอปนี้'),
            false => Flash::error(($secret !== '' ? 'บันทึกแล้ว แต่' : '') . 'RVC-ONE ไม่ยอมรับ client_id / client_secret นี้ (invalid_client) กรุณาตรวจค่าในหน้าแก้ไขแอปของ RVC-ONE'),
            null => Flash::warning(($secret !== '' ? 'บันทึกแล้ว แต่' : '') . 'ติดต่อ RVC-ONE เพื่อตรวจรหัสแอปไม่สำเร็จ ลองกด "ตรวจการเชื่อมต่อ" อีกครั้งภายหลัง'),
        };

        return $this->redirect($response);
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/settings'))->withStatus(302);
    }
}
