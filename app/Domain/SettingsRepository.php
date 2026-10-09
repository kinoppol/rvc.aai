<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use App\Support\Palette;

/**
 * อ่าน/เขียนค่าตั้งค่าระบบจากตาราง settings พร้อมแปลงชนิดข้อมูลให้
 */
final class SettingsRepository
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->all()[$name] ?? $default;
    }

    public function int(string $name, int $default = 0): int
    {
        $value = $this->get($name);

        return $value === null ? $default : (int) $value;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->get($name);

        return $value === null ? $default : (bool) $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];
        foreach ($this->db->all('SELECT name, value, type FROM {settings}') as $row) {
            $this->cache[$row['name']] = $this->cast($row['value'], $row['type']);
        }

        return $this->cache;
    }

    /** โหมดคุณภาพผลลัพธ์ที่ครูคนนี้เลือก: 'fast' (ประหยัด) หรือ 'quality' (คุณภาพสูง) */
    public function userQualityPref(int $userId): string
    {
        return $this->get('ai_quality:' . $userId) === 'quality' ? 'quality' : 'fast';
    }

    /**
     * แหล่ง AI ที่ครูคนนี้อยากให้ใช้ก่อน: 'byok' (AI ของครูเอง) หรือ 'college' (เครื่องของส่วนกลาง)
     * ค่าเริ่มต้นคือ 'byok' — ถ้าครูเชื่อม AI ของตัวเองไว้ ระบบจะใช้ของครูก่อนเสมอ
     */
    public function userRoutePref(int $userId): string
    {
        return $this->get('ai_route:' . $userId) === 'college' ? 'college' : 'byok';
    }

    /**
     * สีหลักของหน้าจอ: ของผู้ใช้คนนี้ก่อน ถ้าไม่ได้ตั้งไว้จึงใช้ค่าที่ผู้ดูแลกำหนดให้ทั้งระบบ
     *
     * @return array{hex:string,own:bool,siteHex:string}
     */
    public function uiPrimary(?int $userId = null): array
    {
        $site = Palette::normalise($this->get('ui_primary'));
        $own = $userId === null ? '' : trim((string) ($this->get('ui_primary:' . $userId) ?? ''));

        return [
            'hex' => $own !== '' ? Palette::normalise($own) : $site,
            'own' => $own !== '',
            'siteHex' => $site,
        ];
    }

    public function set(string $name, mixed $value, string $type = 'string', string $group = 'general'): void
    {
        $stored = $type === 'json' ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;

        $this->db->run(
            'INSERT INTO {settings} (name, value, type, group_name) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), type = VALUES(type)',
            [$name, $stored, $type, $group]
        );

        $this->cache = null;
    }

    private function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => (bool) (int) $value,
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
