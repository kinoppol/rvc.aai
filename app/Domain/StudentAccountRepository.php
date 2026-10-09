<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * บัญชีนักเรียนที่ครูสร้าง/นำเข้าเอง — ใช้ร่วมกันได้ระหว่างครูในสถานศึกษาเดียวกัน (institution_id เดียวกัน)
 * ชื่อผู้ใช้คือรหัสนักศึกษา (unique เฉพาะภายในสถานศึกษา) รหัสผ่านคือเลขประจำตัวประชาชน
 */
final class StudentAccountRepository
{
    /** โดเมนอีเมลที่สร้างให้นักเรียนอัตโนมัติ */
    public const EMAIL_DOMAIN = 'rvc.ac.th';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forInstitution(
        int $institutionId,
        string $search = '',
        string $status = '',
        int $limit = 50,
        int $offset = 0
    ): array {
        [$clause, $params] = $this->filter($institutionId, $search, $status);

        return $this->db->all(
            "SELECT id, username, full_name, email, phone, status, created_at
             FROM {users} $clause
             ORDER BY username
             LIMIT $limit OFFSET $offset",
            $params
        );
    }

    /** จำนวนนักเรียนทั้งหมดตามเงื่อนไขเดียวกับ forInstitution() ใช้คำนวณจำนวนหน้า */
    public function countForInstitution(int $institutionId, string $search = '', string $status = ''): int
    {
        [$clause, $params] = $this->filter($institutionId, $search, $status);

        return $this->db->int("SELECT COUNT(*) FROM {users} $clause", $params);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function filter(int $institutionId, string $search, string $status): array
    {
        $where = ["institution_id = ?", "role = 'student'"];
        $params = [$institutionId];

        if ($search !== '') {
            $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    public function find(int $id, int $institutionId): ?array
    {
        return $this->db->first(
            "SELECT id, username, full_name, email, phone, status
             FROM {users} WHERE id = ? AND institution_id = ? AND role = 'student'",
            [$id, $institutionId]
        );
    }

    /**
     * บัญชีนักเรียนที่ใช้งานอยู่ซึ่งใช้อีเมลนี้ (ไม่สนตัวพิมพ์เล็ก-ใหญ่)
     * อีเมลของนักเรียนไม่ได้บังคับห้ามซ้ำ จึงคืนเป็นรายการให้ผู้เรียกตัดสินเองเมื่อพบมากกว่าหนึ่งบัญชี
     *
     * @return list<array<string,mixed>>
     */
    public function activeByEmail(string $email, ?int $institutionId): array
    {
        $sql = "SELECT id, username, full_name, email FROM {users}
                WHERE LOWER(email) = LOWER(?) AND role = 'student' AND status = 'active'";
        $params = [$email];
        if ($institutionId !== null) {
            $sql .= ' AND institution_id = ?';
            $params[] = $institutionId;
        }

        return $this->db->all($sql . ' ORDER BY username', $params);
    }

    /**
     * นักเรียนในกลุ่มเรียนนี้ (ผูกจากการโอนข้อมูล RMS) พร้อมบอกว่าอยู่ในรายวิชานี้แล้วหรือยัง
     *
     * @return list<array<string,mixed>>
     */
    public function inClassroom(int $classroomId, int $courseId): array
    {
        return $this->db->all(
            "SELECT u.id, u.username, u.full_name, u.email,
                    (SELECT e.status FROM {enrollments} e WHERE e.course_id = ? AND e.student_id = u.id) AS enrollment_status
             FROM {users} u
             WHERE u.classroom_id = ? AND u.role = 'student' AND u.status = 'active'
             ORDER BY u.username",
            [$courseId, $classroomId]
        );
    }

    /**
     * สร้างอีเมลสถานศึกษาให้นักเรียนที่ยังไม่มีอีเมล ในรูปแบบ [รหัสนักศึกษา 11 หลัก]@rvc.ac.th
     * ข้ามคนที่รหัสไม่ใช่ตัวเลข 11 หลัก หรืออีเมลนั้นมีบัญชีอื่นใช้อยู่แล้ว (คอลัมน์ email ห้ามซ้ำ)
     *
     * @param list<int> $userIds
     * @return int จำนวนบัญชีที่สร้างอีเมลให้
     */
    public function fillMissingEmails(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $rows = $this->db->all(
            "SELECT u.id, u.username, p.student_code
             FROM {users} u LEFT JOIN {student_profiles} p ON p.user_id = u.id
             WHERE u.id IN ($in) AND u.role = 'student' AND (u.email IS NULL OR u.email = '')",
            array_values($userIds)
        );

        $filled = 0;
        foreach ($rows as $r) {
            $code = preg_match('/^\d{11}$/', (string) $r['username']) === 1 ? (string) $r['username'] : (string) ($r['student_code'] ?? '');
            if (preg_match('/^\d{11}$/', $code) !== 1) {
                continue;
            }
            $email = $code . '@' . self::EMAIL_DOMAIN;
            if ($this->db->int('SELECT COUNT(*) FROM {users} WHERE email = ?', [$email]) > 0) {
                continue;
            }
            $this->db->update('users', ['email' => $email], ['id' => (int) $r['id']]);
            $filled++;
        }

        return $filled;
    }

    public function usernameTaken(int $institutionId, string $username, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return $this->db->int(
                'SELECT COUNT(*) FROM {users} WHERE institution_id = ? AND username = ? AND id <> ?',
                [$institutionId, $username, $excludeId]
            ) > 0;
        }

        return $this->db->int(
            'SELECT COUNT(*) FROM {users} WHERE institution_id = ? AND username = ?',
            [$institutionId, $username]
        ) > 0;
    }

    /**
     * @param array{username:string,full_name:string,national_id:string,email:?string,phone:?string} $data
     */
    public function create(int $institutionId, array $data): int
    {
        return $this->db->insert('users', [
            'username' => $data['username'],
            'email' => $data['email'] ?: null,
            'password_hash' => password_hash($data['national_id'], PASSWORD_DEFAULT),
            'full_name' => $data['full_name'],
            'role' => 'student',
            'status' => 'active',
            'phone' => $data['phone'] ?: null,
            'institution_id' => $institutionId,
        ]);
    }

    /**
     * แก้ไขข้อมูลนักเรียน — ถ้าส่ง national_id มาด้วยจะรีเซ็ตรหัสผ่านกลับเป็นเลขประจำตัวประชาชนนั้น
     *
     * @param array{full_name:string,email:?string,phone:?string,national_id?:string} $data
     */
    public function update(int $id, array $data): void
    {
        $fields = [
            'full_name' => $data['full_name'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
        ];

        if (!empty($data['national_id'])) {
            $fields['password_hash'] = password_hash($data['national_id'], PASSWORD_DEFAULT);
        }

        $this->db->update('users', $fields, ['id' => $id]);
    }
}
