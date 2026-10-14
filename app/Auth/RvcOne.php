<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Config;

/**
 * ทางเข้าเดียวสำหรับคุยกับ RVC-ONE (SSO ของวิทยาลัย) — ห้ามเรียก endpoint ของ RVC-ONE จากที่อื่น
 *
 * ค่าเชื่อมต่อทั้งหมดอยู่ที่นี่ที่เดียว และแทนที่ได้ด้วย `sso.*` ใน config/config.php
 * (เช่น `'sso' => ['enabled' => false]` เพื่อซ่อนปุ่ม) · การตรวจโทเคนทำฝั่ง server เท่านั้น
 * และห้ามเขียน token_id / token_key ลง log
 */
final class RvcOne
{
    private const DEFAULTS = [
        'enabled'       => true,
        'authorize_url' => 'http://workspace.rvc.ac.th/oa/index.php',
        'verify_url'    => 'http://workspace.rvc.ac.th/oa/api/verify_token.php',
        'client_id'     => 'cl_65d249d300',
        // ต้องตรงกับที่ลงทะเบียนไว้ใน RVC-ONE ทุกตัวอักษร
        'redirect_uri'  => 'https://aai.rvc.ac.th/web/auth/callback',
    ];
    private const TIMEOUT = 10;

    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->setting('enabled');
    }

    public function authorizeUrl(string $state): string
    {
        return $this->setting('authorize_url') . '?' . http_build_query([
            'client_id'    => $this->setting('client_id'),
            'redirect_uri' => $this->setting('redirect_uri'),
            'state'        => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * ตรวจโทเคนกับ RVC-ONE แล้วคืน object user (+ org ถ้ามี ใต้คีย์ `_org`)
     *
     * @return array<string,mixed>
     * @throws RvcOneException reason `invalid` = โทเคนผิด/หมดอายุ, `unavailable` = เรียก API ไม่สำเร็จ
     */
    public function verify(string $tokenId, string $tokenKey): array
    {
        // ตรวจรูปแบบก่อนส่งออกไป — token_id = {user_id}_{expiry_unix}, token_key = HMAC-SHA256 hex
        if (preg_match('/^\d+_\d+$/', $tokenId) !== 1 || preg_match('/^[0-9a-f]+$/i', $tokenKey) !== 1 || strlen($tokenKey) !== 64) {
            throw new RvcOneException('invalid', 'ข้อมูลยืนยันตัวตนจาก RVC-ONE ไม่ถูกต้อง กรุณาเข้าสู่ระบบใหม่อีกครั้ง');
        }

        $ch = curl_init((string) $this->setting('verify_url'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['token_id' => $tokenId, 'token_key' => $tokenKey]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new RvcOneException('unavailable', $errno === CURLE_OPERATION_TIMEDOUT
                ? 'ระบบ RVC-ONE ตอบสนองช้าเกินไป กรุณาลองใหม่อีกครั้ง'
                : 'เชื่อมต่อระบบ RVC-ONE ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        }

        $data = json_decode(ltrim((string) $raw, "\xEF\xBB\xBF \r\n\t"), true);
        if (in_array($status, [400, 401], true) || (is_array($data) && ($data['valid'] ?? null) === false)) {
            throw new RvcOneException('invalid', 'การยืนยันตัวตนจาก RVC-ONE หมดอายุหรือไม่ถูกต้อง กรุณาเข้าสู่ระบบใหม่อีกครั้ง');
        }
        if ($status !== 200 || !is_array($data) || ($data['valid'] ?? null) !== true || !is_array($data['user'] ?? null)) {
            throw new RvcOneException('unavailable', sprintf('ระบบ RVC-ONE ตอบกลับไม่ถูกต้อง (HTTP %d) กรุณาลองใหม่อีกครั้ง', $status));
        }

        $user = $data['user'];
        if (is_array($data['org'] ?? null)) {
            $user['_org'] = $data['org'];
        }

        return $user;
    }

    private function setting(string $key): mixed
    {
        return $this->config->get('sso.' . $key, self::DEFAULTS[$key]);
    }
}
