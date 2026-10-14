<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\SettingsRepository;
use InvalidArgumentException;

/**
 * วิธีเข้าสู่ระบบที่ผู้ดูแลระบบเปิดไว้ (หน้า "ตั้งค่าระบบ") — ต้องเปิดอย่างน้อยหนึ่งวิธีเสมอ
 *
 * - password: ชื่อผู้ใช้ + รหัสผ่าน (รวมรหัสผ่าน RMS ครั้งแรก) · ปิดแล้วผู้ดูแลระบบยังเข้าด้วยรหัสผ่านได้
 *   ที่ /login?admin=1 กันทั้งวิทยาลัยถูกล็อกออกเมื่อ RVC-ONE ล่ม
 * - rvc_one: RVC-ONE (SSO) · ใช้ได้เมื่อ config `sso.enabled` ไม่ได้ปิดไว้ด้วย
 * ไม่มีค่าใน settings = เปิดทั้งสองวิธี
 */
final class LoginMethods
{
    public const PASSWORD = 'login_password';
    public const RVC_ONE = 'login_rvc_one';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RvcOne $rvcOne,
    ) {
    }

    public function password(): bool
    {
        // ถ้า RVC-ONE ใช้ไม่ได้ (เช่นปิดใน config ภายหลัง) ต้องเหลือรหัสผ่านไว้เสมอ
        return $this->settings->bool(self::PASSWORD, true) || !$this->rvcOne();
    }

    public function rvcOne(): bool
    {
        return $this->rvcOneAvailable() && $this->settings->bool(self::RVC_ONE, true);
    }

    /** RVC-ONE ตั้งค่าการเชื่อมต่อไว้แล้ว (ไม่ได้ปิดใน config) */
    public function rvcOneAvailable(): bool
    {
        return $this->rvcOne->enabled();
    }

    /** @throws InvalidArgumentException ข้อความภาษาไทยเมื่อปิดทุกวิธี */
    public function save(bool $password, bool $rvcOne): void
    {
        if (!$password && !($rvcOne && $this->rvcOneAvailable())) {
            throw new InvalidArgumentException('ต้องเปิดวิธีเข้าสู่ระบบอย่างน้อยหนึ่งวิธี');
        }

        $this->settings->set(self::PASSWORD, $password ? '1' : '0', 'boolean', 'auth');
        $this->settings->set(self::RVC_ONE, $rvcOne ? '1' : '0', 'boolean', 'auth');
    }
}
