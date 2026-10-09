<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * รายชื่อสถานศึกษาที่ครู/นักเรียนเลือกตอนสมัคร/ล็อกอิน — ครูจากสถานศึกษาเดียวกัน
 * (institution_id ตรงกัน) มองเห็นและจัดการนักเรียนร่วมกันได้
 */
final class InstitutionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT id, name FROM {institutions} ORDER BY name');
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT id, name FROM {institutions} WHERE id = ?', [$id]);
    }

    /** หาสถานศึกษาจากชื่อ (ตัดช่องว่างหัวท้าย) ถ้ายังไม่มีให้สร้างใหม่ แล้วคืนค่า id */
    public function findOrCreateByName(string $name): int
    {
        $name = trim($name);

        $existing = $this->db->first('SELECT id FROM {institutions} WHERE name = ?', [$name]);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->db->insert('institutions', ['name' => $name]);
    }
}
