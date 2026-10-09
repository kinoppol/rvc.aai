<?php

declare(strict_types=1);

namespace App\Support;

/**
 * อ่านไฟล์บันทึกข้อขัดข้อง (storage/logs/app.log) ที่ Monolog เขียนไว้บรรทัดละหนึ่งเหตุการณ์
 * ใช้กับหน้า /admin/logs เพื่อให้ผู้ดูแลตามดูปัญหาจากหมายเลขอ้างอิงที่ผู้ใช้แจ้งมาได้
 */
final class LogReader
{
    /** อ่านจากท้ายไฟล์เท่านี้พอ ไฟล์ที่โตมากจึงไม่กินหน่วยความจำทั้งก้อน */
    private const MAX_BYTES = 2_097_152;

    private const LINE = '~^\[(?<time>[^\]]+)\]\s+\S+\.(?<level>[A-Z]+):\s+(?<message>.*)$~';

    public function path(): string
    {
        return Paths::storage('logs/app.log');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function size(): int
    {
        return $this->exists() ? (int) filesize($this->path()) : 0;
    }

    /**
     * เหตุการณ์ล่าสุดก่อน กรองด้วยคำค้น (หมายเลขอ้างอิง ข้อความ หรือชื่อไฟล์) ได้
     *
     * @return list<array{time:string,level:string,message:string,ref:?string,raw:string}>
     */
    public function recent(int $limit = 100, string $query = ''): array
    {
        if (!$this->exists()) {
            return [];
        }

        $handle = fopen($this->path(), 'rb');
        if ($handle === false) {
            return [];
        }

        $size = $this->size();
        if ($size > self::MAX_BYTES) {
            fseek($handle, -self::MAX_BYTES, SEEK_END);
            fgets($handle);
        }

        $entries = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || ($query !== '' && stripos($line, $query) === false)) {
                continue;
            }

            $entries[] = $this->parse($line);
        }
        fclose($handle);

        return array_slice(array_reverse($entries), 0, $limit);
    }

    /** @return array{time:string,level:string,message:string,ref:?string,raw:string} */
    private function parse(string $line): array
    {
        $ref = preg_match('~"ref":"([A-Z0-9-]+)"~', $line, $m) === 1 ? $m[1] : null;

        if (preg_match(self::LINE, $line, $parts) !== 1) {
            return ['time' => '', 'level' => 'UNKNOWN', 'message' => $line, 'ref' => $ref, 'raw' => $line];
        }

        return [
            'time' => Thai::dateTime(substr($parts['time'], 0, 19)),
            'level' => $parts['level'],
            'message' => $parts['message'],
            'ref' => $ref,
            'raw' => $line,
        ];
    }
}
