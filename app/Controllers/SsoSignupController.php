<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\LoginMethods;
use App\Auth\Roles;
use App\Domain\InstitutionRepository;
use App\Domain\SettingsRepository;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * นักศึกษาปริญญาตรี (ทล.บ.) ที่เข้าสู่ระบบผ่าน RVC-ONE แล้วยังไม่มีบัญชีในระบบนี้ — กรอกข้อมูลที่จำเป็นเองก่อนเข้าใช้งาน
 *
 * เงื่อนไข: RVC-ONE ยืนยันตัวตนแล้ว อีเมลเป็นรหัสนักศึกษา 11 หลัก @rvc.ac.th และไม่มีบัญชีนักศึกษารหัสนั้น
 * SsoController เก็บข้อมูลที่ verify แล้วไว้ใน `$_SESSION['sso_signup']` (อายุ 30 นาที) แล้วพามาที่นี่ —
 * รหัสนักศึกษาและอีเมลมาจาก RVC-ONE เท่านั้น ผู้ใช้แก้ไม่ได้ จึงสมัครแทนคนอื่นไม่ได้
 *
 * GET  /auth/register-student  ฟอร์มกรอกข้อมูล
 * POST /auth/register-student  สร้างบัญชีนักศึกษา (สถานะใช้งาน รหัสผ่านสุ่ม — เข้าสู่ระบบผ่าน RVC-ONE) แล้วเข้าสู่ระบบให้ทันที
 */
final class SsoSignupController
{
    public const SESSION = 'sso_signup';
    private const TTL = 1800;
    private const EMAIL_PATTERN = '/^(\d{11})@rvc\.ac\.th$/i';
    private const YEARS = [1, 2, 3, 4];

    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Db $db,
        private readonly LoginMethods $methods,
        private readonly InstitutionRepository $institutions,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * รหัสนักศึกษาปริญญาตรีจากอีเมลของผู้ใช้ RVC-ONE — null ถ้าไม่เข้าเงื่อนไขสมัครเอง
     *
     * @param array<string,mixed> $ssoUser
     */
    public static function studentCode(array $ssoUser): ?string
    {
        if (strtolower(trim((string) ($ssoUser['user_type'] ?? ''))) === 'staff') {
            return null;
        }
        foreach (['email', 'google_email'] as $field) {
            if (preg_match(self::EMAIL_PATTERN, trim((string) ($ssoUser[$field] ?? '')), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * เก็บข้อมูลที่ RVC-ONE ยืนยันแล้วไว้ใน session ให้หน้าสมัคร (เรียกจาก SsoController หลัง verify เท่านั้น)
     *
     * @param array<string,mixed> $ssoUser
     */
    public static function remember(array $ssoUser, string $code): void
    {
        $email = '';
        foreach (['email', 'google_email'] as $field) {
            $value = trim((string) ($ssoUser[$field] ?? ''));
            if (preg_match(self::EMAIL_PATTERN, $value) === 1) {
                $email = strtolower($value);
                break;
            }
        }
        $_SESSION[self::SESSION] = [
            'code'       => $code,
            'email'      => $email,
            'oa_id'      => is_numeric($ssoUser['id'] ?? null) ? (int) $ssoUser['id'] : 0,
            'full_name'  => trim(trim((string) ($ssoUser['first_name'] ?? '')) . ' ' . trim((string) ($ssoUser['last_name'] ?? ''))),
            'department' => trim((string) ($ssoUser['department'] ?? '')),
            'at'         => time(),
        ];
    }

    public function show(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/dashboard');
        }
        $signup = $this->signup();
        if ($signup === null) {
            return $this->expired($response);
        }

        return $this->render($response, $signup, [
            'full_name' => $signup['full_name'],
            'major'     => $signup['department'],
            'year'      => '',
            'group'     => '',
            'phone'     => '',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/dashboard');
        }
        $signup = $this->signup();
        if ($signup === null) {
            return $this->expired($response);
        }
        $data = (array) $request->getParsedBody();
        $form = [
            'full_name' => self::clean($data['full_name'] ?? ''),
            'major'     => self::clean($data['major'] ?? ''),
            'year'      => (string) ($data['year'] ?? ''),
            'group'     => self::clean($data['group'] ?? ''),
            'phone'     => self::clean($data['phone'] ?? ''),
        ];

        $errors = [];
        if (!Csrf::check($data['_token'] ?? null)) {
            $errors[] = 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง';
        }
        if (!$this->methods->rvcOne()) {
            $errors[] = 'ผู้ดูแลระบบปิดการเข้าสู่ระบบผ่าน RVC-ONE ไว้';
        }
        if (mb_strlen($form['full_name']) < 3 || mb_strlen($form['full_name']) > 150) {
            $errors[] = 'กรุณากรอกชื่อ-นามสกุล (3–150 ตัวอักษร)';
        }
        if ($form['major'] === '' || mb_strlen($form['major']) > 150) {
            $errors[] = 'กรุณากรอกสาขาวิชา';
        }
        if (!in_array((int) $form['year'], self::YEARS, true)) {
            $errors[] = 'กรุณาเลือกชั้นปี';
        }
        if (mb_strlen($form['group']) > 150) {
            $errors[] = 'ชื่อกลุ่มเรียนยาวเกินไป';
        }
        if ($form['phone'] !== '' && preg_match('/^[0-9+\- ]{9,20}$/', $form['phone']) !== 1) {
            $errors[] = 'เบอร์โทรศัพท์ไม่ถูกต้อง';
        }
        if (empty($data['confirm'])) {
            $errors[] = 'กรุณายืนยันว่าข้อมูลถูกต้อง';
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                Flash::error($error);
            }

            return $this->render($response->withStatus(422), $signup, $form);
        }

        // ระหว่างกรอกฟอร์มอาจมีบัญชีรหัสนี้เกิดขึ้นแล้ว (เช่นซิงค์จาก RVC-ONE / RMS) — ให้เข้าสู่ระบบใหม่แทนการสร้างซ้ำ
        if ($this->db->int("SELECT COUNT(*) FROM {users} WHERE username = ? AND role = 'student'", [$signup['code']]) > 0) {
            unset($_SESSION[self::SESSION]);
            Flash::warning('มีบัญชีนักศึกษารหัส ' . $signup['code'] . ' ในระบบแล้ว กรุณาเข้าสู่ระบบผ่าน RVC-ONE อีกครั้ง');

            return $this->redirect($response, '/login');
        }

        try {
            $userId = $this->create($signup, $form);
        } catch (Throwable $e) {
            $this->logger->error('Bachelor self-signup failed: ' . $e->getMessage(), ['code' => $signup['code'], 'exception' => $e]);
            Flash::error('บันทึกข้อมูลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ');

            return $this->render($response->withStatus(500), $signup, $form);
        }

        unset($_SESSION[self::SESSION]);
        $user = $this->db->first('SELECT id, role FROM {users} WHERE id = ?', [$userId]);
        $this->auth->linkSso($userId, (int) $signup['oa_id']);
        $this->auth->start($user ?? ['id' => $userId, 'role' => Roles::STUDENT]);
        $this->auth->log('register.student_sso', $signup['code'], [
            'sso_user_id' => $signup['oa_id'],
            'major'       => $form['major'],
            'year'        => (int) $form['year'],
        ]);
        Flash::success('สร้างบัญชีนักศึกษาเรียบร้อยแล้ว ยินดีต้อนรับ — ครั้งต่อไปเข้าสู่ระบบผ่าน RVC-ONE ได้เลย');

        $intended = $this->auth->pullIntended();

        return $intended !== null
            ? $response->withHeader('Location', $intended)->withStatus(302)
            : $this->redirect($response, '/dashboard');
    }

    /**
     * @param array{code:string,email:string,oa_id:int} $signup
     * @param array{full_name:string,major:string,year:string,group:string,phone:string} $form
     */
    private function create(array $signup, array $form): int
    {
        $institutionId = $this->institutions->findOrCreateByName(
            (string) ($this->settings->get('college_name') ?: $this->config->get('app.college', 'วิทยาลัยเทคนิคร้อยเอ็ด'))
        );
        $email = $signup['email'];
        if ($email === '' || $this->db->int('SELECT COUNT(*) FROM {users} WHERE LOWER(email) = LOWER(?)', [$email]) > 0) {
            $email = null; // คอลัมน์ email ห้ามซ้ำ — ไม่ให้การสมัครล้มเพราะอีเมล
        }
        // กลุ่มเรียนที่พิมพ์ตรงกับชื่อกลุ่มในระบบพอดี จะผูกกับกลุ่มนั้นให้
        $classroomId = $form['group'] !== ''
            ? $this->db->value('SELECT id FROM {classrooms} WHERE name = ? OR group_code = ? ORDER BY id DESC LIMIT 1', [$form['group'], $form['group']])
            : null;

        return (int) $this->db->transaction(function () use ($signup, $form, $institutionId, $email, $classroomId): int {
            $userId = $this->db->insert('users', [
                'username'       => $signup['code'],
                'email'          => $email,
                // RVC-ONE เป็นผู้ยืนยันตัวตน — รหัสผ่านในระบบนี้สุ่มไว้ (ขอรีเซ็ตจากผู้ดูแลได้ถ้าจำเป็น)
                'password_hash'  => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'full_name'      => $form['full_name'],
                'phone'          => $form['phone'] !== '' ? $form['phone'] : null,
                'role'           => Roles::STUDENT,
                'status'         => 'active',
                'institution_id' => $institutionId,
                'classroom_id'   => $classroomId !== null && $classroomId !== false ? (int) $classroomId : null,
            ]);
            $this->db->insert('student_profiles', [
                'user_id'      => $userId,
                'student_id'   => $signup['code'],
                'student_code' => $signup['code'],
                'group_name'   => $form['group'] !== '' ? $form['group'] : null,
                'grade_name'   => 'ทล.บ. ปี ' . (int) $form['year'],
                'major_name'   => $form['major'],
            ]);

            return $userId;
        });
    }

    /** @return ?array{code:string,email:string,oa_id:int,full_name:string,department:string,at:int} */
    private function signup(): ?array
    {
        $s = $_SESSION[self::SESSION] ?? null;
        if (!is_array($s) || !is_string($s['code'] ?? null) || time() - (int) ($s['at'] ?? 0) > self::TTL) {
            unset($_SESSION[self::SESSION]);

            return null;
        }

        return $s;
    }

    /**
     * @param array<string,mixed> $signup
     * @param array<string,string> $form
     */
    private function render(Response $response, array $signup, array $form): Response
    {
        return $this->view->render($response, 'auth/register-student', $form + [
            'code'   => $signup['code'],
            'email'  => $signup['email'],
            'years'  => self::YEARS,
            'majors' => $this->suggest('SELECT DISTINCT major_name FROM {student_profiles} WHERE major_name IS NOT NULL AND major_name <> \'\' ORDER BY major_name LIMIT 200'),
            'groups' => $this->suggest("SELECT DISTINCT name FROM {classrooms} WHERE name LIKE '%ทล%' OR grade LIKE '%ทล%' OR name LIKE '%ปริญญา%' ORDER BY name LIMIT 200"),
        ]);
    }

    /** @return list<string> รายการแนะนำใน datalist — ตารางยังไม่มีคอลัมน์ก็ไม่เป็นไร */
    private function suggest(string $sql): array
    {
        try {
            return array_map(static fn (array $r): string => (string) reset($r), $this->db->all($sql));
        } catch (Throwable) {
            return [];
        }
    }

    private function expired(Response $response): Response
    {
        Flash::warning('หมดเวลากรอกข้อมูลสมัครใช้งาน กรุณาเข้าสู่ระบบผ่าน RVC-ONE อีกครั้ง');

        return $this->redirect($response, '/login');
    }

    private static function clean(mixed $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
