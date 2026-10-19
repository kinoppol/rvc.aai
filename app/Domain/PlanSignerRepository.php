<?php

declare(strict_types=1);

namespace App\Domain;

use App\Auth\Roles;
use App\Support\Db;
use PDOException;

/**
 * ผู้ลงนามในแผนการจัดการเรียนรู้ที่สั่งพิมพ์ (หน้าลักษณะรายวิชา)
 *
 *   หัวหน้าแผนกวิชา                     ครูเลือกเองต่อรายวิชา (courses.dept_head_id)
 *   หัวหน้างานพัฒนาหลักสูตร / รองฯ วิชาการ / ผู้อำนวยการ   ผู้ดูแลระบบกำหนดครั้งเดียวทั้งวิทยาลัย (settings)
 *
 * ทุกตำแหน่งเลือกจากรายชื่อบุคลากรในระบบ (บัญชีครู/ผู้ดูแลที่ยังใช้งานอยู่)
 */
final class PlanSignerRepository
{
    /** คีย์ใน settings => ชื่อตำแหน่ง */
    public const POSITIONS = [
        'plan_sign_curriculum' => 'หัวหน้างานพัฒนาหลักสูตรการเรียนการสอน',
        'plan_sign_deputy'     => 'รองผู้อำนวยการฝ่ายวิชาการ',
        'plan_sign_director'   => 'ผู้อำนวยการ',
    ];

    private ?bool $column = null;

    public function __construct(
        private readonly Db $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    /** @return list<array{id:int, full_name:string}> บุคลากรที่ยังใช้งานอยู่ เรียงตามชื่อ */
    public function staff(): array
    {
        $roles = Roles::STAFF;
        $in = implode(',', array_fill(0, count($roles), '?'));

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'full_name' => (string) $r['full_name']],
            $this->db->all(
                "SELECT id, full_name FROM {users}
                 WHERE role IN ($in) AND status = 'active' AND TRIM(full_name) <> ''
                 ORDER BY full_name",
                $roles
            )
        );
    }

    /** ชื่อบุคลากรตาม id · ไม่พบ/ว่าง = null */
    public function name(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        $name = $this->db->value('SELECT full_name FROM {users} WHERE id = ?', [$userId]);

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** id ของบุคคลที่ตั้งไว้แต่ละตำแหน่ง (คีย์ตาม POSITIONS · ยังไม่ตั้ง = 0) */
    public function chosen(): array
    {
        $out = [];
        foreach (array_keys(self::POSITIONS) as $key) {
            $out[$key] = $this->settings->int($key, 0);
        }

        return $out;
    }

    /** ชื่อผู้ลงนามของแต่ละตำแหน่งที่ผู้ดูแลระบบตั้งไว้ (คีย์ตาม POSITIONS · ยังไม่ตั้ง = null) */
    public function names(): array
    {
        return array_map(fn (int $id): ?string => $this->name($id), $this->chosen());
    }

    /** @param array<string,int> $ids คีย์ตาม POSITIONS · 0 = ไม่ระบุ · id ที่ไม่ใช่บุคลากรถูกตัดเป็น 0 */
    public function save(array $ids): void
    {
        $valid = array_column($this->staff(), 'id');
        foreach (array_keys(self::POSITIONS) as $key) {
            $id = (int) ($ids[$key] ?? 0);
            $this->settings->set($key, in_array($id, $valid, true) ? $id : 0, 'integer', 'plan');
        }
    }

    /** id ที่ครูส่งมาเป็นบุคลากรจริงหรือไม่ — ไม่ใช่ = null */
    public function validStaff(int $id): ?int
    {
        return $id > 0 && in_array($id, array_column($this->staff(), 'id'), true) ? $id : null;
    }

    /** หัวหน้าแผนกที่ครูคนนี้เลือกไว้ในรายวิชาล่าสุด — ใช้เป็นค่าตั้งต้นของรายวิชาใหม่ */
    public function lastDeptHead(int $teacherId): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return $this->db->int(
            'SELECT dept_head_id FROM {courses} WHERE teacher_id = ? AND dept_head_id IS NOT NULL ORDER BY updated_at DESC, id DESC LIMIT 1',
            [$teacherId]
        );
    }

    /** มีคอลัมน์ courses.dept_head_id แล้วหรือยัง (ไมเกรชัน 000128) — ก่อนรันไมเกรชันจะได้ไม่บันทึกรายวิชาพัง */
    public function ready(): bool
    {
        if ($this->column === null) {
            try {
                $this->column = $this->db->first("SHOW COLUMNS FROM {courses} LIKE 'dept_head_id'") !== null;
            } catch (PDOException) {
                $this->column = false;
            }
        }

        return $this->column;
    }
}
