<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Auth\Roles;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * รายชื่อผู้ใช้งานระบบสำหรับผู้ดูแล — ดูตามบทบาท สวมสิทธิ์ชั่วคราว และอนุมัติ/ปฏิเสธ
 * ครูที่สมัครสมาชิกเอง (สถานะ "pending" จาก `RegisterController`)
 */
final class UsersController
{
    private const PER_PAGE = 50;
    private const STATUSES = ['active', 'pending', 'suspended'];

    public function __construct(
        private readonly View $view,
        private readonly Db $db,
        private readonly Auth $auth,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $role = (string) ($request->getQueryParams()['role'] ?? '');
        $counts = [];
        foreach ($this->db->all("SELECT role, COUNT(*) AS n FROM {users} GROUP BY role") as $row) {
            $counts[$row['role']] = (int) $row['n'];
        }
        $pendingCount = $this->db->int("SELECT COUNT(*) FROM {users} WHERE status = 'pending'");

        if ($role === 'pending') {
            $pendingUsers = $this->db->all(
                "SELECT id, username, full_name, email, subject_area, institution, created_at
                 FROM {users} WHERE status = 'pending' ORDER BY created_at"
            );
            foreach ($pendingUsers as $i => $u) {
                $pendingUsers[$i]['created_text'] = Thai::ago($u['created_at']);
            }

            return $this->view->render($response, 'admin/users', [
                'page' => 'admin-users',
                'users' => [],
                'pendingUsers' => $pendingUsers,
                'counts' => $counts,
                'filter' => 'pending',
                'pendingCount' => $pendingCount,
                'requireApproval' => $this->settings->bool('registration_require_approval', true),
            ] + $this->adminContext($request));
        }

        $query = $request->getQueryParams();
        $roles = [Roles::ADMIN, Roles::SUPERVISOR, Roles::TEACHER, Roles::STUDENT];
        $search = trim((string) ($query['q'] ?? ''));
        $status = in_array($query['status'] ?? '', self::STATUSES, true) ? (string) $query['status'] : '';

        $where = [];
        $params = [];

        if (in_array($role, $roles, true)) {
            $where[] = 'role = ?';
            $params[] = $role;
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = $this->db->int("SELECT COUNT(*) FROM {users} $clause", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($query['page'] ?? 1)));
        $offset = ($page - 1) * self::PER_PAGE;

        $users = $this->db->all(
            "SELECT id, username, full_name, email, role, status, last_login_at
             FROM {users} $clause
             ORDER BY FIELD(status, 'pending', 'active', 'suspended'),
                      FIELD(role, 'admin', 'supervisor', 'teacher', 'student'), username
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );
        foreach ($users as $i => $u) {
            $users[$i]['last_login_text'] = $u['last_login_at'] ? Thai::ago($u['last_login_at']) : 'ยังไม่เคยเข้าใช้';
            $users[$i]['role_label'] = Roles::label((string) $u['role']);
        }

        return $this->view->render($response, 'admin/users', [
            'page' => 'admin-users',
            'users' => $users,
            'pendingUsers' => [],
            'counts' => $counts,
            'filter' => $role,
            'pendingCount' => $pendingCount,
            'requireApproval' => $this->settings->bool('registration_require_approval', true),
            'search' => $search,
            'status' => $status,
            'total' => $total,
            'pageNo' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'queryString' => http_build_query(array_filter([
                'role' => $role,
                'status' => $status,
                'q' => $search,
            ], static fn (string $v): bool => $v !== '')),
        ] + $this->adminContext($request));
    }

    /**
     * สิ่งที่หน้าผู้ใช้งานต้องรู้เพิ่ม — ผู้ดูแลครูเห็นรายชื่อได้ แต่ปุ่มสวมสิทธิ์ รีเซ็ตรหัสผ่าน
     * และการแต่งตั้งบทบาทเป็นของผู้ดูแลระบบเท่านั้น
     *
     * @return array<string,mixed>
     */
    private function adminContext(Request $request): array
    {
        return [
            'isAdmin' => (($request->getAttribute('user')['role'] ?? '')) === Roles::ADMIN,
            'roleOptions' => [
                Roles::TEACHER => Roles::label(Roles::TEACHER),
                Roles::SUPERVISOR => Roles::label(Roles::SUPERVISOR),
                Roles::ADMIN => Roles::label(Roles::ADMIN),
            ],
        ];
    }

    /** อนุมัติครูที่สมัครสมาชิกเอง — เปิดให้เข้าสู่ระบบได้ */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $id = (int) $args['id'];
        $target = $this->db->first('SELECT full_name FROM {users} WHERE id = ?', [$id]);

        if ($target === null || !$this->auth->approveTeacher($id)) {
            Flash::error('อนุมัติไม่สำเร็จ — อาจถูกดำเนินการไปแล้วหรือไม่พบคำขอนี้');

            return $this->redirect($response);
        }

        $this->auth->log('user.approve', 'user#' . $id);
        Flash::success('อนุมัติบัญชีของ ' . $target['full_name'] . ' แล้ว · เข้าสู่ระบบได้ทันที');

        return $this->redirect($response);
    }

    /** ปฏิเสธคำขอสมัคร — ลบบัญชีที่ยังไม่เคยใช้งานจริงทิ้ง */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $id = (int) $args['id'];
        $target = $this->db->first('SELECT full_name FROM {users} WHERE id = ?', [$id]);

        if ($target === null || !$this->auth->rejectTeacher($id)) {
            Flash::error('ปฏิเสธไม่สำเร็จ — อาจถูกดำเนินการไปแล้วหรือไม่พบคำขอนี้');

            return $this->redirect($response);
        }

        $this->auth->log('user.reject', 'user#' . $id);
        Flash::warning('ปฏิเสธคำขอสมัครของ ' . $target['full_name'] . ' แล้ว');

        return $this->redirect($response);
    }

    /** รีเซ็ตรหัสผ่านทันที — สุ่มรหัสผ่านใหม่ให้ แล้วโชว์ครั้งเดียวให้ผู้ดูแลนำไปแจ้งเจ้าของบัญชี */
    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $id = (int) $args['id'];
        $target = $this->db->first(
            "SELECT full_name FROM {users} WHERE id = ? AND role IN ('teacher', 'admin') AND status = 'active'",
            [$id]
        );

        if ($target === null) {
            Flash::error('ไม่พบบัญชีนี้ หรือบัญชีถูกระงับอยู่');

            return $this->redirect($response);
        }

        $password = $this->auth->resetPasswordNow($id);
        $this->auth->log('user.password.reset', 'user#' . $id);

        Flash::success(sprintf(
            'รีเซ็ตรหัสผ่านของ %s แล้ว · รหัสผ่านใหม่คือ %s (แจ้งเจ้าของบัญชีให้เปลี่ยนรหัสผ่านทันทีหลังเข้าใช้)',
            $target['full_name'],
            $password
        ));

        return $this->redirect($response);
    }

    /** สร้างลิงก์รีเซ็ตรหัสผ่านให้คัดลอกไปส่งเอง (ระบบยังไม่มีตัวส่งอีเมล) — หมดอายุใน 60 นาที */
    public function resetLink(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $id = (int) $args['id'];
        $target = $this->db->first(
            "SELECT full_name FROM {users} WHERE id = ? AND role IN ('teacher', 'admin') AND status = 'active'",
            [$id]
        );

        if ($target === null) {
            Flash::error('ไม่พบบัญชีนี้ หรือบัญชีถูกระงับอยู่');

            return $this->redirect($response);
        }

        $token = $this->auth->createPasswordResetToken($id);
        $link = rtrim((string) $this->config->get('app.url'), '/') . '/reset-password/' . $token;
        $this->auth->log('user.password.reset_link', 'user#' . $id);

        Flash::success(sprintf(
            'สร้างลิงก์รีเซ็ตรหัสผ่านของ %s แล้ว (หมดอายุใน 60 นาที) คัดลอกไปส่งให้เจ้าของบัญชีเอง: %s',
            $target['full_name'],
            $link
        ));

        return $this->redirect($response);
    }

    /** ผู้ดูแลระบบแต่งตั้งบทบาทของบุคลากร — ครูผู้สอน / ผู้ดูแลครู / ผู้ดูแลระบบ */
    public function changeRole(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $id = (int) $args['id'];
        $role = (string) ($data['role'] ?? '');
        $actor = $request->getAttribute('user');

        if (!in_array($role, Roles::ASSIGNABLE, true)) {
            Flash::error('บทบาทที่เลือกไม่ถูกต้อง');

            return $this->redirect($response);
        }

        if ($id === (int) $actor['id']) {
            Flash::error('เปลี่ยนบทบาทของบัญชีตัวเองไม่ได้ ให้ผู้ดูแลระบบคนอื่นเป็นผู้เปลี่ยนให้');

            return $this->redirect($response);
        }

        $target = $this->db->first('SELECT full_name, role FROM {users} WHERE id = ?', [$id]);
        if ($target === null || $target['role'] === Roles::STUDENT) {
            Flash::error('เปลี่ยนบทบาทได้เฉพาะบัญชีบุคลากรเท่านั้น');

            return $this->redirect($response);
        }

        // กันเผลอถอดผู้ดูแลระบบคนสุดท้ายออกจนไม่มีใครเข้าถึงส่วนที่สงวนไว้ได้อีก
        $admins = $this->db->int("SELECT COUNT(*) FROM {users} WHERE role = 'admin' AND status = 'active'");
        if ($target['role'] === Roles::ADMIN && $role !== Roles::ADMIN && $admins <= 1) {
            Flash::error('ต้องเหลือผู้ดูแลระบบอย่างน้อยหนึ่งบัญชี');

            return $this->redirect($response);
        }

        $this->db->update('users', ['role' => $role], ['id' => $id]);
        $this->auth->log('user.role.change', 'user#' . $id, ['from' => $target['role'], 'to' => $role]);

        Flash::success('ตั้ง ' . $target['full_name'] . ' เป็น' . Roles::label($role) . 'แล้ว');

        return $this->redirect($response);
    }

    /** บันทึกการตั้งค่าการสมัครสมาชิก — ต้องอนุมัติก่อนหรือเข้าใช้ได้เลย */
    public function saveSettings(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $require = isset($data['require_approval']) && $data['require_approval'] === '1';
        $this->settings->set('registration_require_approval', $require ? '1' : '0', 'boolean', 'registration');
        $this->auth->log('registration.settings', null, ['require_approval' => $require]);

        Flash::success($require
            ? 'เปิดการอนุมัติแล้ว ครูที่สมัครใหม่จะต้องรอผู้ดูแลอนุมัติก่อน'
            : 'ปิดการอนุมัติแล้ว ครูที่สมัครใหม่จะเข้าใช้งานได้ทันที'
        );

        return $response->withHeader('Location', Url::to('/admin/users?role=pending'))->withStatus(302);
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/users'))->withStatus(302);
    }
}
