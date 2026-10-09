<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ผู้ดูแลระบบสวมสิทธิ์ผู้ใช้อื่นเพื่อช่วยแก้ปัญหา แล้วกลับมาเป็นผู้ดูแลได้เมื่อออกจากระบบ
 */
final class ImpersonationController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Db $db,
    ) {
    }

    /** เริ่มสวมสิทธิ์ — เข้าถึงได้เฉพาะผู้ดูแล (อยู่ในกลุ่มเส้นทาง /admin) */
    public function start(Request $request, Response $response, array $args): Response
    {
        $admin = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/admin/users');
        }

        if ($this->auth->isImpersonating()) {
            Flash::error('กำลังสวมสิทธิ์ผู้ใช้อื่นอยู่แล้ว กรุณากลับเป็นผู้ดูแลก่อน');

            return $this->redirect($response, '/admin/users');
        }

        $targetId = (int) $args['id'];
        $target = $this->db->first(
            'SELECT id, username, full_name, role, status FROM {users} WHERE id = ?',
            [$targetId]
        );

        if ($target === null) {
            Flash::error('ไม่พบผู้ใช้ที่ต้องการ');

            return $this->redirect($response, '/admin/users');
        }
        if ((int) $target['id'] === (int) $admin['id']) {
            Flash::error('สวมสิทธิ์ตัวเองไม่ได้');

            return $this->redirect($response, '/admin/users');
        }
        if ($target['role'] === 'admin') {
            Flash::error('สวมสิทธิ์บัญชีผู้ดูแลระบบด้วยกันไม่ได้');

            return $this->redirect($response, '/admin/users');
        }
        if ($target['status'] !== 'active') {
            Flash::error('บัญชีนี้ถูกระงับการใช้งาน จึงสวมสิทธิ์ไม่ได้');

            return $this->redirect($response, '/admin/users');
        }

        // บันทึกก่อนสลับ เพื่อให้ผู้กระทำในบันทึกคือผู้ดูแล
        $this->auth->log('impersonate.start', 'user#' . $target['id'], [
            'username' => $target['username'],
            'role' => $target['role'],
        ]);

        $this->auth->impersonate($target);
        Flash::success(sprintf('กำลังใช้งานในนาม %s · กด "กลับเป็นผู้ดูแล" เมื่อเสร็จ', $target['full_name']));

        return $this->redirect($response, '/dashboard');
    }

    /** กลับมาเป็นผู้ดูแลเดิม — เข้าถึงได้ทุกบทบาทที่ล็อกอินอยู่ */
    public function stop(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->redirect($response, '/dashboard');
        }

        if (!$this->auth->isImpersonating()) {
            return $this->redirect($response, '/dashboard');
        }

        $leftUserId = (int) ($_SESSION['user_id'] ?? 0);

        if ($this->auth->stopImpersonating()) {
            $this->auth->log('impersonate.stop', 'user#' . $leftUserId);
            Flash::success('กลับมาเป็นผู้ดูแลระบบแล้ว');

            return $this->redirect($response, '/admin/users');
        }

        Flash::error('บัญชีผู้ดูแลเดิมใช้งานไม่ได้ กรุณาเข้าสู่ระบบใหม่');

        return $this->redirect($response, '/login');
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
