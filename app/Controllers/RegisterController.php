<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\InstitutionRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ครูทั่วไปสมัครเข้าใช้ระบบเอง — สถานะขึ้นกับการตั้งค่า registration_require_approval
 * ถ้าเปิดอยู่จะเป็น pending รอผู้ดูแลอนุมัติ / ถ้าปิดจะเป็น active ใช้งานได้ทันที
 */
final class RegisterController
{
    private const USERNAME_PATTERN = '/^[a-z0-9._-]{3,64}$/';
    private const MIN_PASSWORD_LENGTH = 8;
    private const NEW_INSTITUTION_VALUE = '__new__';

    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
        private readonly SettingsRepository $settings,
        private readonly InstitutionRepository $institutions,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/dashboard');
        }

        return $this->view->render($response, 'auth/register', $this->emptyForm() + [
            'institutions' => $this->institutions->all(),
        ]);
    }

    public function register(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->view->render($response, 'auth/register', $this->emptyForm() + [
                'institutions' => $this->institutions->all(),
            ]);
        }

        $institutionChoice = (string) ($data['institution_id'] ?? '');
        $institutionNew = trim((string) ($data['institution_new'] ?? ''));
        $institutionName = $institutionChoice === self::NEW_INSTITUTION_VALUE
            ? $institutionNew
            : (string) ($this->institutions->find((int) $institutionChoice)['name'] ?? '');

        $form = [
            'username' => trim((string) ($data['username'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'subject_area' => trim((string) ($data['subject_area'] ?? '')),
            'institution' => $institutionName,
            'institution_id' => $institutionChoice,
            'institution_new' => $institutionNew,
        ];
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirm'] ?? '');

        $errors = $this->validate($form, $password, $confirm);

        if ($errors !== []) {
            foreach ($errors as $error) {
                Flash::error($error);
            }

            return $this->view->render($response, 'auth/register', $form + [
                'institutions' => $this->institutions->all(),
            ]);
        }

        $requireApproval = $this->settings->bool('registration_require_approval', true);
        $status = $requireApproval ? 'pending' : 'active';

        // ส่งค่าทีละช่อง ไม่รวมร่างจาก $form เพราะใน $form เก็บ institution_id เป็นค่าที่เลือกจากฟอร์ม
        // ซึ่งอาจเป็น '__new__' และตัวดำเนินการ + จะไม่ยอมให้ค่าใหม่ทับคีย์เดิมที่มีอยู่แล้ว
        $this->auth->registerTeacher([
            'username' => $form['username'],
            'email' => $form['email'],
            'full_name' => $form['full_name'],
            'phone' => $form['phone'],
            'subject_area' => $form['subject_area'],
            'institution' => $institutionName,
            'institution_id' => $this->institutions->findOrCreateByName($institutionName),
            'password' => $password,
        ], $status);
        $this->auth->log('register.teacher', $form['username'], [
            'institution' => $form['institution'],
            'subject_area' => $form['subject_area'],
            'auto_approved' => !$requireApproval,
        ]);

        if ($requireApproval) {
            Flash::success(
                'ส่งคำขอสมัครสมาชิกแล้ว บัญชีของคุณจะใช้งานได้หลังผู้ดูแลระบบตรวจสอบและอนุมัติ '
                . 'กรุณารอการติดต่อกลับ'
            );
        } else {
            Flash::success('สมัครสมาชิกสำเร็จ เข้าสู่ระบบได้ทันที');
        }

        return $this->redirect($response, '/login');
    }

    /** @return list<string> */
    private function validate(array $form, string $password, string $confirm): array
    {
        $errors = [];

        if (preg_match(self::USERNAME_PATTERN, $form['username']) !== 1) {
            $errors[] = 'ชื่อผู้ใช้ต้องยาว 3 ตัวขึ้นไป ใช้ได้เฉพาะ a-z 0-9 . _ -';
        } elseif ($this->auth->usernameTaken($form['username'])) {
            $errors[] = 'ชื่อผู้ใช้นี้มีคนใช้แล้ว กรุณาเลือกชื่ออื่น';
        }

        if ($form['full_name'] === '') {
            $errors[] = 'กรุณากรอกชื่อ-สกุล';
        }

        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        } elseif ($this->auth->emailTaken($form['email'])) {
            $errors[] = 'อีเมลนี้มีการใช้งานในระบบแล้ว';
        }

        if ($form['subject_area'] === '') {
            $errors[] = 'กรุณาระบุสาขาวิชาที่สอน';
        }

        if ($form['institution'] === '') {
            $errors[] = 'กรุณาเลือกหรือระบุสถานศึกษาที่สังกัด';
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = sprintf('รหัสผ่านต้องยาวอย่างน้อย %d ตัวอักษร', self::MIN_PASSWORD_LENGTH);
        } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $errors[] = 'รหัสผ่านต้องมีทั้งตัวอักษรและตัวเลข';
        }

        if ($password !== $confirm) {
            $errors[] = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
        }

        return $errors;
    }

    /** @return array<string,string> */
    private function emptyForm(): array
    {
        return [
            'username' => '', 'email' => '', 'full_name' => '',
            'phone' => '', 'subject_area' => '', 'institution' => '',
            'institution_id' => '', 'institution_new' => '',
        ];
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
