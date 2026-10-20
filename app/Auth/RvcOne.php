<?php

declare(strict_types=1);

namespace App\Auth;

use App\AI\KeyCipher;
use App\Domain\SettingsRepository;
use App\Support\Config;
use SensitiveParameter;
use Throwable;

/**
 * ทางเข้าเดียวสำหรับคุยกับ RVC-ONE (SSO ของวิทยาลัย) — ห้ามเรียก endpoint ของ RVC-ONE จากที่อื่น
 *
 * ค่าเชื่อมต่อทั้งหมดอยู่ที่นี่ที่เดียว และแทนที่ได้ด้วย `sso.*` ใน config/config.php
 * (เช่น `'sso' => ['enabled' => false]` เพื่อซ่อนปุ่ม) · การตรวจโทเคนทำฝั่ง server เท่านั้น
 * และห้ามเขียน token_id / token_key / client_secret ลง log
 */
final class RvcOne
{
    /** client_secret ที่ผู้ดูแลกรอกในหน้าตั้งค่าระบบ — เก็บแบบเข้ารหัสด้วย KeyCipher */
    public const SECRET_SETTING = 'rvc_one_client_secret';

    private const DEFAULTS = [
        'enabled'       => true,
        'authorize_url' => 'http://workspace.rvc.ac.th/oa/index.php',
        'verify_url'    => 'http://workspace.rvc.ac.th/oa/api/verify_token.php',
        'client_id'     => 'cl_65d249d300',
        // ความลับของแอปที่ RVC-ONE ออกให้ — verify_token.php ต้องการ HTTP Basic (client_id:client_secret)
        // ตั้งจากหน้า "ตั้งค่าระบบ" (เก็บเข้ารหัสใน settings) หรือ `sso.client_secret` ใน config/config.php ห้าม commit ลง git
        'client_secret' => '',
        // ต้องตรงกับที่ลงทะเบียนไว้ใน RVC-ONE ทุกตัวอักษร
        'redirect_uri'  => 'https://aai.rvc.ac.th/web/auth/callback',
        // User-sync API — ดึงรายชื่อผู้ใช้แบบ server-to-server ด้วย client_id / client_secret ชุดเดียวกับ SSO
        'users_url'     => 'http://workspace.rvc.ac.th/oa/api/users.php',
    ];
    private const TIMEOUT = 10;
    /** User-sync หน้าละไม่เกิน 1,000 คน ใช้เวลานานกว่าการตรวจโทเคน */
    private const USERS_TIMEOUT = 60;
    /** เรียกซ้ำเมื่อเชื่อมต่อไม่ได้ / 429 / 5xx — ไม่เกินจำนวนนี้ */
    private const USERS_ATTEMPTS = 3;

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepository $settings,
        private readonly KeyCipher $cipher,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->setting('enabled');
    }

    public function clientId(): string
    {
        return (string) $this->setting('client_id');
    }

    public function authorizeUrl(string $state): string
    {
        return $this->setting('authorize_url') . '?' . http_build_query([
            'client_id'    => $this->clientId(),
            'redirect_uri' => $this->setting('redirect_uri'),
            'state'        => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * client_secret ที่ใช้อยู่ และมาจากไหน — ค่าในหน้าตั้งค่าระบบมาก่อน ไม่มีจึงใช้ config
     *
     * @return array{secret:string,source:string} source = settings | config | ''
     */
    private function secret(): array
    {
        $stored = (string) ($this->settings->get(self::SECRET_SETTING) ?? '');
        if ($stored !== '') {
            try {
                return ['secret' => $this->cipher->decrypt($stored), 'source' => 'settings'];
            } catch (Throwable) {
                // เข้ารหัสด้วย app.key คนละชุด — ถือว่ายังไม่ได้ตั้ง ให้ผู้ดูแลกรอกใหม่
            }
        }
        $fromConfig = (string) $this->setting('client_secret');

        return ['secret' => $fromConfig, 'source' => $fromConfig !== '' ? 'config' : ''];
    }

    /**
     * สถานะ client_secret สำหรับหน้าตั้งค่า (ไม่คืนค่าลับ)
     *
     * @return array{source:string,last4:string,broken:bool}
     */
    public function secretStatus(): array
    {
        ['secret' => $secret, 'source' => $source] = $this->secret();
        $stored = (string) ($this->settings->get(self::SECRET_SETTING) ?? '');

        return [
            'source' => $source,
            'last4'  => $secret !== '' ? $this->cipher->last4($secret) : '',
            // มีค่าเก็บไว้แต่ถอดรหัสไม่ได้ (app.key เปลี่ยน)
            'broken' => $stored !== '' && $source !== 'settings',
        ];
    }

    public function saveSecret(#[SensitiveParameter] string $secret): void
    {
        $this->settings->set(self::SECRET_SETTING, $secret === '' ? '' : $this->cipher->encrypt($secret), 'string', 'auth');
    }

    /**
     * ถาม RVC-ONE ว่ารับ client_id / client_secret ที่ตั้งไว้หรือไม่ (ส่งโทเคนปลอมที่หมดอายุแล้วไปตรวจ)
     *
     * @return bool|null true = รับ, false = invalid_client, null = ตอบไม่ได้ (เชื่อมต่อไม่สำเร็จ / ตอบแปลก)
     */
    public function checkClient(): ?bool
    {
        ['secret' => $secret] = $this->secret();
        if ($secret === '') {
            return false;
        }

        try {
            [$status, $data] = $this->post('0_0', str_repeat('0', 64), $secret);
        } catch (RvcOneException) {
            return null;
        }
        $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : '';
        if ($error === 'invalid_client') {
            return false;
        }

        // ผ่านด่านรหัสแอปแล้ว จึงตอบว่าโทเคนใช้ไม่ได้
        return is_array($data) && ($data['valid'] ?? null) === false ? true : ($status === 200 ? true : null);
    }

    /**
     * ตรวจโทเคนกับ RVC-ONE แล้วคืน object user (+ org ถ้ามี ใต้คีย์ `_org`)
     *
     * @return array<string,mixed>
     * @throws RvcOneException reason `invalid` = โทเคนผิด/หมดอายุ, `unavailable` = เรียก API ไม่สำเร็จ,
     *                         `config` = client_id / client_secret ของแอปไม่ถูกต้องหรือยังไม่ได้ตั้ง
     */
    public function verify(#[SensitiveParameter] string $tokenId, #[SensitiveParameter] string $tokenKey): array
    {
        // ตรวจรูปแบบก่อนส่งออกไป — token_id = {user_id}_{expiry_unix}, token_key = HMAC-SHA256 hex
        if (preg_match('/^\d+_\d+$/', $tokenId) !== 1 || preg_match('/^[0-9a-f]+$/i', $tokenKey) !== 1 || strlen($tokenKey) !== 64) {
            throw new RvcOneException('invalid', 'ข้อมูลยืนยันตัวตนจาก RVC-ONE ไม่ถูกต้อง กรุณาเข้าสู่ระบบใหม่อีกครั้ง');
        }

        ['secret' => $secret] = $this->secret();
        if ($secret === '') {
            throw new RvcOneException('config', 'ยังไม่ได้ตั้งค่า client_secret ของ RVC-ONE ในระบบนี้ กรุณาแจ้งผู้ดูแลระบบ');
        }

        [$status, $data] = $this->post($tokenId, $tokenKey, $secret);

        $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : '';
        if ($error === 'invalid_client') {
            // แอปเราเอง (client_id / client_secret) ไม่ผ่าน ไม่ใช่โทเคนของผู้ใช้
            throw new RvcOneException('config', 'RVC-ONE ไม่ยอมรับรหัสแอปของระบบนี้ (client_id / client_secret ไม่ถูกต้อง) กรุณาแจ้งผู้ดูแลระบบ', $error);
        }
        if (in_array($status, [400, 401], true) || (is_array($data) && ($data['valid'] ?? null) === false)) {
            throw new RvcOneException('invalid', 'การยืนยันตัวตนจาก RVC-ONE หมดอายุหรือไม่ถูกต้อง กรุณาเข้าสู่ระบบใหม่อีกครั้ง', $error);
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

    /**
     * ดึงรายชื่อผู้ใช้หนึ่งหน้าจาก User-sync API ของ RVC-ONE (เรียกจากฝั่ง server เท่านั้น)
     *
     * @param array{type?:?string,since?:?string,limit?:int,cursor?:?string} $params ส่ง cursor แล้วไม่ต้องส่ง type/since ซ้ำ
     * @return array{users:list<array<string,mixed>>,has_more:bool,next_cursor:?string,server_time:string}
     * @throws RvcOneException reason `config` = รหัสแอปผิด/ยังไม่ได้ตั้ง/ยังไม่ได้รับสิทธิ์, `invalid` = พารามิเตอร์ผิด
     *                         (error = bad_cursor ฯลฯ), `unavailable` = เรียก API ไม่สำเร็จหลังลองซ้ำครบแล้ว
     */
    public function users(array $params): array
    {
        ['secret' => $secret] = $this->secret();
        if ($secret === '') {
            throw new RvcOneException('config', 'ยังไม่ได้ตั้งค่า client_secret ของ RVC-ONE — ตั้งได้ที่หน้าตั้งค่าระบบ (การเชื่อมต่อ RVC-ONE)');
        }
        // secret ไปทาง HTTP Basic เท่านั้น ห้ามอยู่ใน query string
        $url = (string) $this->setting('users_url') . '?' . http_build_query(array_filter(
            $params,
            static fn (mixed $v): bool => $v !== null && $v !== ''
        ), '', '&', PHP_QUERY_RFC3986);

        for ($attempt = 1; ; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET        => true,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_USERPWD        => $this->clientId() . ':' . $secret,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::USERS_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $retry = $raw === false || $errno !== 0 || $status === 429 || $status >= 500;
            if (!$retry || $attempt >= self::USERS_ATTEMPTS) {
                break;
            }
            sleep($attempt * 2);
        }

        if ($raw === false || $errno !== 0) {
            throw new RvcOneException('unavailable', sprintf(
                $errno === CURLE_OPERATION_TIMEDOUT
                    ? 'RVC-ONE ตอบสนองช้าเกินไประหว่างดึงรายชื่อผู้ใช้ (ลองแล้ว %d ครั้ง)'
                    : 'เชื่อมต่อ RVC-ONE ไม่สำเร็จระหว่างดึงรายชื่อผู้ใช้ (ลองแล้ว %d ครั้ง)',
                self::USERS_ATTEMPTS
            ));
        }

        $data = json_decode(ltrim((string) $raw, "\xEF\xBB\xBF \r\n\t"), true);
        $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : '';
        if ($status !== 200 || !is_array($data) || ($data['ok'] ?? null) !== true) {
            [$reason, $message] = match ($error) {
                'invalid_client'   => ['config', 'RVC-ONE ไม่ยอมรับรหัสแอปของระบบนี้ (client_id / client_secret ไม่ถูกต้อง หรือแอปถูกปิดใช้งาน)'],
                'sync_disabled'    => ['config', 'RVC-ONE ยังไม่ได้เปิดสิทธิ์ User-sync API ให้แอปนี้ กรุณาแจ้งผู้ดูแล RVC-ONE'],
                'type_not_allowed' => ['config', 'แอปนี้ไม่ได้รับอนุญาตให้ดึงผู้ใช้ประเภทที่ขอจาก RVC-ONE'],
                'bad_type', 'bad_since', 'bad_cursor' => ['invalid', 'พารามิเตอร์ที่ส่งไป RVC-ONE ไม่ถูกต้อง (' . $error . ')'],
                default            => ['unavailable', sprintf('RVC-ONE ตอบกลับไม่ถูกต้องระหว่างดึงรายชื่อผู้ใช้ (HTTP %d)', $status)],
            };

            throw new RvcOneException($reason, $message, $error);
        }

        return [
            'users'       => array_values(array_filter((array) ($data['users'] ?? []), 'is_array')),
            'has_more'    => ($data['has_more'] ?? false) === true,
            'next_cursor' => is_string($data['next_cursor'] ?? null) && $data['next_cursor'] !== '' ? $data['next_cursor'] : null,
            'server_time' => is_string($data['server_time'] ?? null) ? $data['server_time'] : '',
        ];
    }

    /**
     * @return array{0:int,1:mixed} HTTP status + JSON ที่ถอดแล้ว
     * @throws RvcOneException `unavailable` เมื่อเชื่อมต่อไม่สำเร็จ
     */
    private function post(
        #[SensitiveParameter] string $tokenId,
        #[SensitiveParameter] string $tokenKey,
        #[SensitiveParameter] string $secret,
    ): array {
        $ch = curl_init((string) $this->setting('verify_url'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['token_id' => $tokenId, 'token_key' => $tokenKey]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->clientId() . ':' . $secret,
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

        return [$status, json_decode(ltrim((string) $raw, "\xEF\xBB\xBF \r\n\t"), true)];
    }

    private function setting(string $key): mixed
    {
        return $this->config->get('sso.' . $key, self::DEFAULTS[$key]);
    }
}
