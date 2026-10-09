<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ตัวเรียก HTTP เล็ก ๆ สำหรับคุยกับบริการ AI จริง (cURL)
 * แยกไว้ต่างหากเพื่อให้ผู้ให้บริการแต่ละเจ้าเขียนสั้นและทดสอบง่าย
 */
final class HttpClient
{
    /**
     * @param int $timeout วินาทีที่ยอมรอจนกว่าจะสตรีมจบ — เครื่องในวิทยาลัยที่ไม่มี GPU แรง
     *                     ใช้เวลาหลายนาทีกับงานยาว ๆ อย่างข้อสอบหรือแผนการสอน
     */
    public function __construct(
        private readonly int $connectTimeout = 8,
        private readonly int $timeout = 600,
    ) {
    }

    /**
     * เรียกแบบรอผลครั้งเดียว
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $json
     * @return array{status:int,body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?array $json = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->baseOptions($method, $headers, $json) + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new AiUnavailableException('generate_failed', 'ต่อกับบริการ AI ไม่ได้: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * POST แล้วทยอยคืนเนื้อหาทีละบรรทัดระหว่างที่ปลายทางยังส่งมา (ใช้ curl_multi เพื่อให้ yield ได้จริง)
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $json
     * @return iterable<string>
     */
    public function lines(string $url, array $headers, array $json): iterable
    {
        $buffer = '';
        $pending = [];
        $status = 0;
        $errorBody = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, $this->baseOptions('POST', $headers, $json) + [
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, &$pending, &$status, &$errorBody): int {
                if ($status === 0) {
                    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                }

                // สถานะไม่ใช่ 2xx → เก็บเนื้อหาไว้แจ้งผู้ใช้แทนที่จะสตรีมออกไป
                if ($status >= 400) {
                    $errorBody .= $chunk;

                    return strlen($chunk);
                }

                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $pending[] = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);
                }

                return strlen($chunk);
            },
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        do {
            $mrc = curl_multi_exec($mh, $running);
            while ($pending !== []) {
                yield array_shift($pending);
            }
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $mrc === CURLM_OK);

        $error = curl_error($ch);
        $status = $status ?: (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        curl_close($ch);

        if ($status >= 400) {
            throw new AiUnavailableException(
                $status === 401 || $status === 403 ? 'key_invalid' : 'generate_failed',
                self::describeError($status, $errorBody)
            );
        }

        if ($status === 0 || $error !== '') {
            throw new AiUnavailableException('generate_failed', 'ต่อกับบริการ AI ไม่ได้: ' . ($error ?: 'ไม่ได้รับคำตอบ'));
        }

        while ($pending !== []) {
            yield array_shift($pending);
        }

        if ($buffer !== '') {
            yield rtrim($buffer, "\r");
        }
    }

    /** แปลงข้อความ error ของปลายทางให้เป็นภาษาไทยที่ครูอ่านรู้เรื่อง */
    public static function describeError(int $status, string $body): string
    {
        $detail = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $detail = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? '');
        }
        $detail = mb_substr(trim($detail) ?: trim(strip_tags($body)), 0, 200);

        $head = match (true) {
            $status === 401, $status === 403 => 'รหัสเชื่อมต่อใช้ไม่ได้หรือหมดอายุ',
            $status === 404 => 'ไม่พบโมเดลที่เลือกไว้ในบัญชีนี้',
            $status === 429 => 'เรียกใช้ถี่เกินโควตาของผู้ให้บริการ กรุณารอสักครู่',
            $status >= 500 => 'บริการ AI ปลายทางขัดข้องชั่วคราว',
            default => 'บริการ AI ตอบกลับผิดพลาด (รหัส ' . $status . ')',
        };

        return $detail === '' ? $head : $head . ' · ' . $detail;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $json
     * @return array<int,mixed>
     */
    private function baseOptions(string $method, array $headers, ?array $json): array
    {
        $head = [];
        foreach ($headers as $name => $value) {
            $head[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $head,
        ];

        if ($json !== null) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($json, JSON_UNESCAPED_UNICODE);
        }

        return $options;
    }
}
