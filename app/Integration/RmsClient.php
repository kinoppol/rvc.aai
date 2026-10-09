<?php

declare(strict_types=1);

namespace App\Integration;

use App\Domain\SettingsRepository;

/**
 * ทางเข้าเดียวสำหรับดึงข้อมูลจากระบบ RMS ของวิทยาลัย — ห้ามเขียน curl ไปหา RMS ที่อื่นอีก
 *
 * host เก็บใน settings (`rms_base_url`) ให้ผู้ดูแลย้ายเซิร์ฟเวอร์ได้เอง ส่วน path และ app_name
 * ตายตัวในโค้ด เพื่อไม่ให้หน้าตั้งค่ากลายเป็นช่องทางสั่งเซิร์ฟเวอร์ไปเรียก URL ใดก็ได้
 * RMS คืน JSON array ของ object เสมอ ทุกค่าเป็น string และวันที่ว่างเป็น "0000-00-00"
 */
final class RmsClient
{
    public const SETTING = 'rms_base_url';
    public const DEFAULT_URL = 'http://rms.rvc.ac.th';
    private const PATH = '/api_connection.php';
    private const APP_NAME = 'nutty';
    private const TIMEOUT = 60;

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->settings->get(self::SETTING) ?: self::DEFAULT_URL), '/');
    }

    /** ตรวจและจัดรูป URL ก่อนบันทึก คืน null ถ้าไม่ใช่ http(s) */
    public static function normalizeUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if (preg_match('#^https?://[^/\s?#]+(?::\d+)?(?:/[^\s?#]*)?$#i', $url) !== 1) {
            return null;
        }

        return $url;
    }

    /**
     * @param array<string,string|int> $params พารามิเตอร์เสริม เช่น ['limit' => '0,100'] หรือ ['count' => 'yes']
     * @return list<array<string,mixed>>
     */
    public function fetch(string $dataset, array $params = []): array
    {
        $url = $this->baseUrl() . self::PATH . '?' . http_build_query(['app_name' => self::APP_NAME, 'data' => $dataset] + $params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false, // RMS ภายในวิทยาลัยมักใช้ cert ที่เซ็นเอง
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RmsException('เชื่อมต่อระบบ RMS ไม่สำเร็จ: ' . $error);
        }
        if ($status >= 400) {
            throw new RmsException(sprintf('ระบบ RMS ตอบกลับด้วยรหัส HTTP %d', $status));
        }

        // บางครั้ง RMS แนบ BOM มาข้างหน้า
        $data = json_decode(ltrim((string) $raw, "\xEF\xBB\xBF \r\n\t"), true);
        if (!is_array($data)) {
            throw new RmsException('ข้อมูลจาก RMS ไม่อยู่ในรูปแบบ JSON ที่ถูกต้อง');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /** จำนวนแถวทั้งหมดของชุดข้อมูล (ใช้ทำแถบความคืบหน้า) — RMS ใช้คีย์ c หรือ count แล้วแต่ endpoint */
    public function count(string $dataset): int
    {
        $rows = $this->fetch($dataset, ['count' => 'yes']);

        return (int) ($rows[0]['c'] ?? $rows[0]['count'] ?? 0);
    }
}
