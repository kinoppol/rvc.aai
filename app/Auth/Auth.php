<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly PDO $db,
        private readonly string $prefix,
    ) {
    }

    /**
     * @param int|null $institutionId ระบุเมื่อเป็นการล็อกอินของนักเรียน (เลือกสถานศึกษามาด้วย)
     *                                 ไม่ระบุ = ล็อกอินของครู/ผู้ดูแลระบบ (ค้นหาจากชื่อผู้ใช้ทั้งระบบ)
     * @return array{ok:bool,message:string}
     */
    public function attempt(string $username, string $password, string $ip, ?int $institutionId = null): array
    {
        if ($this->isThrottled($username, $ip)) {
            return ['ok' => false, 'message' => sprintf(
                'พยายามเข้าสู่ระบบผิดเกิน %d ครั้ง กรุณารอ %d นาทีแล้วลองใหม่',
                self::MAX_ATTEMPTS,
                self::WINDOW_MINUTES
            )];
        }

        $user = $this->findByUsername($username, $institutionId);
        $valid = $user !== null && password_verify($password, $user['password_hash']);

        $this->record($username, $ip, 'login', $valid);

        if (!$valid) {
            return ['ok' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
        }

        if ($user['status'] !== 'active') {
            $message = $user['status'] === 'pending'
                ? 'บัญชีนี้อยู่ระหว่างรอผู้ดูแลระบบอนุมัติ กรุณารอการติดต่อกลับ'
                : 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';

            return ['ok' => false, 'message' => $message];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->updateHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->start($user);

        return ['ok' => true, 'message' => 'เข้าสู่ระบบสำเร็จ'];
    }

    public function start(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];

        $stmt = $this->db->prepare(sprintf('UPDATE `%susers` SET last_login_at = NOW() WHERE id = ?', $this->prefix));
        $stmt->execute([(int) $user['id']]);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * ผู้ดูแลระบบสวมสิทธิ์ผู้ใช้อื่นชั่วคราว — เก็บ id เดิมไว้เพื่อกลับคืนตอนออกจากระบบ
     * ไม่แตะ last_login_at ของบัญชีปลายทาง
     */
    public function impersonate(array $target): void
    {
        $original = (int) ($_SESSION['user_id'] ?? 0);
        session_regenerate_id(true);
        $_SESSION['impersonator_id'] = $_SESSION['impersonator_id'] ?? $original;
        $_SESSION['user_id'] = (int) $target['id'];
        $_SESSION['user_role'] = $target['role'];
    }

    public function isImpersonating(): bool
    {
        return !empty($_SESSION['impersonator_id']);
    }

    public function impersonatorId(): ?int
    {
        return $this->isImpersonating() ? (int) $_SESSION['impersonator_id'] : null;
    }

    public function impersonatorName(): ?string
    {
        $id = $this->impersonatorId();
        if ($id === null) {
            return null;
        }

        $stmt = $this->db->prepare(sprintf('SELECT full_name FROM `%susers` WHERE id = ?', $this->prefix));
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        return $name === false ? 'ผู้ดูแลระบบ' : (string) $name;
    }

    /**
     * กลับไปเป็นบัญชีผู้ดูแลเดิม คืนค่า true ถ้าทำได้
     * ถ้าบัญชีเดิมหายหรือถูกระงับ จะล้างเซสชันทั้งหมดเพื่อความปลอดภัย
     */
    public function stopImpersonating(): bool
    {
        if (!$this->isImpersonating()) {
            return false;
        }

        $adminId = (int) $_SESSION['impersonator_id'];
        unset($_SESSION['impersonator_id']);

        $stmt = $this->db->prepare(sprintf(
            'SELECT id, role, status FROM `%susers` WHERE id = ?',
            $this->prefix
        ));
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();

        if ($admin === false || $admin['role'] !== 'admin' || $admin['status'] !== 'active') {
            $this->logout();

            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $admin['id'];
        $_SESSION['user_role'] = $admin['role'];

        return true;
    }

    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $stmt = $this->db->prepare(sprintf(
            'SELECT id, username, email, full_name, role, status, last_login_at FROM `%susers` WHERE id = ?',
            $this->prefix
        ));
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user === false || $user['status'] !== 'active') {
            $this->logout();

            return null;
        }

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @param int|null $institutionId ระบุเมื่อค้นหาบัญชีนักเรียน (username unique เฉพาะภายในสถานศึกษา)
     *                                 ไม่ระบุ = ค้นหาบัญชีครู/ผู้ดูแลระบบ (username unique ทั้งระบบ)
     */
    public function findByUsername(string $username, ?int $institutionId = null): ?array
    {
        if ($institutionId !== null) {
            $stmt = $this->db->prepare(sprintf(
                "SELECT id, username, email, full_name, password_hash, role, status
                 FROM `%susers` WHERE institution_id = ? AND username = ? AND role = 'student'",
                $this->prefix
            ));
            $stmt->execute([$institutionId, $username]);
        } else {
            $stmt = $this->db->prepare(sprintf(
                "SELECT id, username, email, full_name, password_hash, role, status
                 FROM `%susers` WHERE username = ? AND role IN ('admin', 'supervisor', 'teacher')",
                $this->prefix
            ));
            $stmt->execute([$username]);
        }
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function usernameTaken(string $username): bool
    {
        $stmt = $this->db->prepare(sprintf('SELECT COUNT(*) FROM `%susers` WHERE username = ?', $this->prefix));
        $stmt->execute([$username]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function emailTaken(string $email): bool
    {
        $stmt = $this->db->prepare(sprintf('SELECT COUNT(*) FROM `%susers` WHERE email = ?', $this->prefix));
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * ครูสมัครสมาชิกเอง — status ขึ้นกับการตั้งค่าของระบบ ('pending' หรือ 'active')
     *
     * @param array{username:string,email:string,password:string,full_name:string,phone:?string,subject_area:string,institution:string,institution_id:int} $data
     */
    public function registerTeacher(array $data, string $status = 'pending'): int
    {
        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO `%susers`
                (username, email, password_hash, full_name, role, status, phone, subject_area, institution, institution_id)
             VALUES (?, ?, ?, ?, \'teacher\', ?, ?, ?, ?, ?)',
            $this->prefix
        ));
        $stmt->execute([
            $data['username'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['full_name'],
            $status,
            $data['phone'] ?: null,
            $data['subject_area'],
            $data['institution'],
            $data['institution_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** อนุมัติครูที่สมัครเอง — เปลี่ยนสถานะ pending เป็น active เท่านั้น ไม่แตะบัญชีอื่น */
    public function approveTeacher(int $id): bool
    {
        $stmt = $this->db->prepare(sprintf(
            "UPDATE `%susers` SET status = 'active' WHERE id = ? AND role = 'teacher' AND status = 'pending'",
            $this->prefix
        ));
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /** ปฏิเสธคำขอสมัคร — ลบบัญชีทิ้งเลยเพราะยังไม่เคยใช้งานจริง (จำกัดเฉพาะสถานะ pending กันลบบัญชีอื่นโดยไม่ตั้งใจ) */
    public function rejectTeacher(int $id): bool
    {
        $stmt = $this->db->prepare(sprintf(
            "DELETE FROM `%susers` WHERE id = ? AND role = 'teacher' AND status = 'pending'",
            $this->prefix
        ));
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    public function isThrottled(string $username, string $ip, string $scope = 'login'): bool
    {
        $stmt = $this->db->prepare(sprintf(
            'SELECT COUNT(*) FROM `%slogin_attempts`
             WHERE scope = ? AND succeeded = 0 AND attempted_at > (NOW() - INTERVAL ? MINUTE)
               AND (identifier = ? OR ip_address = ?)',
            $this->prefix
        ));
        $stmt->execute([$scope, self::WINDOW_MINUTES, $username, $ip]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function record(string $identifier, string $ip, string $scope, bool $succeeded): void
    {
        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO `%slogin_attempts` (identifier, ip_address, scope, succeeded) VALUES (?, ?, ?, ?)',
            $this->prefix
        ));
        $stmt->execute([mb_substr($identifier, 0, 191), $ip, $scope, $succeeded ? 1 : 0]);
    }

    public function log(string $action, ?string $target = null, array $meta = []): void
    {
        // เมื่อผู้ดูแลกำลังสวมสิทธิ์ ให้บันทึกด้วยว่าใครเป็นคนสั่งจริง
        if ($this->isImpersonating()) {
            $meta['via_admin_id'] = $this->impersonatorId();
        }

        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO `%saudit_logs` (user_id, action, target, meta, ip_address) VALUES (?, ?, ?, ?, ?)',
            $this->prefix
        ));
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $target,
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * ผู้ดูแลระบบรีเซ็ตรหัสผ่านให้ผู้ใช้คนหนึ่งทันที — สุ่มรหัสผ่านใหม่และคืนค่าให้แจ้งเจ้าของบัญชีเอง
     * ยกเลิกลิงก์รีเซ็ตที่ยังค้างอยู่ของบัญชีนี้ด้วย เพื่อไม่ให้ใช้ซ้ำได้อีก
     */
    public function resetPasswordNow(int $userId): string
    {
        $password = bin2hex(random_bytes(5));
        $this->updateHash($userId, password_hash($password, PASSWORD_DEFAULT));
        $this->invalidatePasswordResetTokens($userId);

        return $password;
    }

    /**
     * สร้างลิงก์รีเซ็ตรหัสผ่านให้ผู้ใช้คนหนึ่ง — ผู้ดูแลคัดลอกลิงก์ไปส่งให้เจ้าของบัญชีเอง (ระบบยังไม่มีตัวส่งอีเมล)
     * คืนค่า token ดิบ (เก็บลงฐานข้อมูลเฉพาะค่าแฮช) หมดอายุใน 60 นาที
     */
    public function createPasswordResetToken(int $userId, int $minutesValid = 60): string
    {
        $this->invalidatePasswordResetTokens($userId);

        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO `%spassword_resets` (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
            $this->prefix
        ));
        $stmt->execute([$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + $minutesValid * 60)]);

        return $token;
    }

    /**
     * ตรวจ token จากลิงก์รีเซ็ต คืนข้อมูลผู้ใช้ถ้ายังไม่หมดอายุ ไม่งั้นคืน null
     */
    public function userForPasswordResetToken(string $token): ?array
    {
        $stmt = $this->db->prepare(sprintf(
            'SELECT u.id, u.username, u.full_name, u.status FROM `%spassword_resets` r
             JOIN `%susers` u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.expires_at > NOW()',
            $this->prefix,
            $this->prefix
        ));
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    /** ตั้งรหัสผ่านใหม่ด้วย token จากลิงก์รีเซ็ต แล้วเผาลิงก์นั้นทิ้ง */
    public function resetPasswordWithToken(string $token, string $newPassword): bool
    {
        $user = $this->userForPasswordResetToken($token);
        if ($user === null) {
            return false;
        }

        $this->updateHash((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));
        $this->invalidatePasswordResetTokens((int) $user['id']);

        return true;
    }

    private function invalidatePasswordResetTokens(int $userId): void
    {
        $stmt = $this->db->prepare(sprintf('DELETE FROM `%spassword_resets` WHERE user_id = ?', $this->prefix));
        $stmt->execute([$userId]);
    }

    private function updateHash(int $id, string $hash): void
    {
        $stmt = $this->db->prepare(sprintf('UPDATE `%susers` SET password_hash = ? WHERE id = ?', $this->prefix));
        $stmt->execute([$hash, $id]);
    }
}
