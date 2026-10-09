<?php

declare(strict_types=1);

namespace App\AI;

use RuntimeException;
use SensitiveParameter;

/**
 * เข้ารหัส/ถอดรหัสคีย์ AI ของครูด้วยกุญแจของระบบ (app.key)
 * ใช้ AES-256-GCM เก็บผลเป็น base64 ของ nonce|tag|ciphertext
 */
final class KeyCipher
{
    private readonly string $key;

    public function __construct(#[SensitiveParameter] string $appKey)
    {
        $decoded = base64_decode($appKey, true);
        if ($decoded === false || strlen($decoded) < 32) {
            throw new RuntimeException('app.key ไม่ถูกต้อง กรุณาติดตั้งระบบใหม่');
        }

        $this->key = substr(hash('sha256', $decoded, true), 0, 32);
    }

    public function encrypt(#[SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($cipher === false) {
            throw new RuntimeException('เข้ารหัสคีย์ไม่สำเร็จ');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(#[SensitiveParameter] string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('ข้อมูลคีย์เสียหาย');
        }

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('ถอดรหัสคีย์ไม่สำเร็จ — คีย์อาจถูกเข้ารหัสด้วยกุญแจคนละชุด');
        }

        return $plain;
    }

    public function last4(#[SensitiveParameter] string $plain): string
    {
        return substr($plain, -4) ?: '';
    }
}
