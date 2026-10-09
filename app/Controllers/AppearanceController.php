<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Palette;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * สีหลักของหน้าจอ: ผู้ดูแลตั้งค่าเริ่มต้นให้ทั้งระบบ ส่วนผู้ใช้แต่ละคนเลือกสีของตัวเองทับได้
 */
final class AppearanceController
{
    public function __construct(
        private readonly View $view,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $current = $this->settings->uiPrimary((int) $user['id']);

        return $this->view->render($response, 'settings/appearance', [
            'page' => 'appearance',
            'presets' => Palette::PRESETS,
            'current' => $current['hex'],
            'usesOwn' => $current['own'],
            'siteHex' => $current['siteHex'],
            'isAdmin' => ($user['role'] ?? '') === 'admin',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $uid = (int) $user['id'];
        $isAdmin = ($user['role'] ?? '') === 'admin';

        // กดปุ่ม "กลับไปใช้สีของส่วนกลาง" = ลบสีส่วนตัวทิ้ง
        if (($data['reset'] ?? '') === '1') {
            $this->settings->set('ui_primary:' . $uid, '', 'string', 'ui');
            Flash::success('กลับไปใช้สีหลักที่ส่วนกลางกำหนดแล้ว');

            return $this->redirect($response);
        }

        $hex = Palette::normalise((string) ($data['color'] ?? ''));
        $this->settings->set('ui_primary:' . $uid, $hex, 'string', 'ui');

        // ผู้ดูแลตั้งเป็นค่าเริ่มต้นของทุกคนได้ในคราวเดียว
        $asDefault = $isAdmin && ($data['as_default'] ?? '') === '1';
        if ($asDefault) {
            $this->settings->set('ui_primary', $hex, 'string', 'ui');
            $this->auth->log('ui.primary.site', $hex);
        }

        Flash::success($asDefault
            ? 'บันทึกสีหลักแล้ว · ใช้เป็นค่าเริ่มต้นของผู้ใช้ทุกคนที่ยังไม่ได้ตั้งสีเอง'
            : 'บันทึกสีหลักของคุณแล้ว');

        return $this->redirect($response);
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/settings/appearance'))->withStatus(302);
    }
}
