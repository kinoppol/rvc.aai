<?php

declare(strict_types=1);

namespace App\Integration;

use App\Auth\Roles;
use App\Domain\InstitutionRepository;
use App\Domain\SettingsRepository;
use App\Support\Config;
use App\Support\Db;

/**
 * โอนข้อมูลจาก RMS เข้าระบบ — บุคลากร (people) กลุ่มเรียน (std2018_studentgroup) และนักเรียน (std2018_student)
 *
 * ทุกขั้นตอน idempotent: upsert ด้วยคีย์ธรรมชาติของ RMS กดซ้ำกี่ครั้งข้อมูลก็ไม่ซ้ำ
 * และไม่ทับค่าที่มนุษย์ตั้งไว้ (บทบาท รหัสผ่านที่ผู้ใช้เปลี่ยนแล้ว การระงับบัญชีด้วยมือ)
 */
final class RmsSync
{
    public const PEOPLE = 'people';
    public const GROUPS = 'std2018_studentgroup';
    public const STUDENTS = 'std2018_student';

    public function __construct(
        private readonly RmsClient $rms,
        private readonly Db $db,
        private readonly InstitutionRepository $institutions,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * บุคลากร → users (role ครู) จับคู่ด้วย people_id หรือชื่อผู้ใช้เดิม
     * คนที่หายจาก RMS หรือ people_exit ≠ 0 จะถูกระงับ (ไม่ลบ) และเปิดคืนเองเมื่อกลับมา
     *
     * @return array{created:int,updated:int,deactivated:int,reactivated:int,skipped:int,active_source:int,fetched:int}
     */
    public function people(): array
    {
        $rows = $this->rms->fetch(self::PEOPLE);
        $institutionId = $this->institutionId();
        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'reactivated' => 0, 'password' => 0, 'skipped' => 0, 'active_source' => 0, 'fetched' => count($rows)];

        $this->db->transaction(function () use ($rows, $institutionId, &$stats): void {
            $seen = [];
            foreach ($rows as $p) {
                if (self::str($p['people_exit'] ?? '') !== '0') {
                    continue;
                }
                $peopleId = self::str($p['people_id'] ?? '');
                $fullName = self::str(self::str($p['people_name'] ?? '') . ' ' . self::str($p['people_surname'] ?? ''));
                if ($peopleId === '' || $fullName === '' || mb_strlen($peopleId) > 30) {
                    $stats['skipped']++;
                    continue;
                }
                $stats['active_source']++;
                $rmsPass = self::md5($p['people_pass'] ?? null);
                $seen[$peopleId] = true;

                $user = $this->db->first(
                    "SELECT id, status, institution_id, rms_deactivated_at, rms_pass_seen FROM {users}
                     WHERE people_id = ? OR (username = ? AND role <> 'student')
                     ORDER BY people_id = ? DESC LIMIT 1",
                    [$peopleId, $peopleId, $peopleId]
                );

                if ($user === null && $this->db->int(
                    'SELECT COUNT(*) FROM {users} WHERE institution_id = ? AND username = ?',
                    [$institutionId, $peopleId]
                ) > 0) {
                    $stats['skipped']++; // ชื่อผู้ใช้ชนกับบัญชีนักเรียน
                    continue;
                }
                if ($user === null) {
                    $this->db->insert('users', [
                        'username'       => $peopleId,
                        'people_id'      => $peopleId,
                        'email'          => $this->freeEmail(self::nullable($p['people_email'] ?? null), null),
                        // มี people_pass = ล็อกอินครั้งแรกด้วยรหัสผ่าน RMS เท่านั้น จึงตั้ง hash สุ่มที่ไม่มีใครรู้ไว้แทน
                        // ไม่มี = ใช้รหัสบุคลากรเป็นรหัสผ่านเริ่มต้นเหมือนเดิม
                        'password_hash'  => password_hash($rmsPass !== null ? bin2hex(random_bytes(24)) : $peopleId, PASSWORD_DEFAULT),
                        'rms_pass_md5'   => $rmsPass,
                        'rms_pass_seen'  => $rmsPass,
                        'full_name'      => $fullName,
                        'role'           => Roles::TEACHER,
                        'status'         => 'active',
                        'institution_id' => $institutionId,
                    ]);
                    $stats['created']++;
                    continue;
                }

                // ไม่แตะ role / password_hash / created_at ที่แอดมินหรือผู้ใช้ตั้งเองแล้ว
                $fields = [
                    'people_id' => $peopleId,
                    'full_name' => $fullName,
                ];
                $email = $this->freeEmail(self::nullable($p['people_email'] ?? null), (int) $user['id']);
                if ($email !== null) {
                    $fields['email'] = $email;
                }
                if ($user['institution_id'] === null) {
                    $fields['institution_id'] = $institutionId;
                }
                // รับรหัสผ่าน RMS ใหม่เฉพาะเมื่อ people_pass เปลี่ยนจากที่โอนครั้งก่อน — โอนซ้ำค่าเดิมจะไม่เปิดรหัสที่ใช้ไปแล้วกลับมา
                if ($rmsPass !== null && $rmsPass !== $user['rms_pass_seen']) {
                    $fields['rms_pass_md5'] = $rmsPass;
                    $fields['rms_pass_seen'] = $rmsPass;
                    $stats['password']++;
                }
                // เปิดคืนเฉพาะคนที่การ sync เป็นผู้ระงับ — คนที่แอดมินระงับเองยังคงถูกระงับ
                if ($user['status'] === 'suspended' && $user['rms_deactivated_at'] !== null) {
                    $fields['status'] = 'active';
                    $fields['rms_deactivated_at'] = null;
                    $stats['reactivated']++;
                }
                $this->db->update('users', $fields, ['id' => (int) $user['id']]);
                $stats['updated']++;
            }

            // RMS ตอบกลับว่างเปล่า (เช่นต้นทางมีปัญหา) อย่าระงับบุคลากรทั้งวิทยาลัย
            if ($seen === []) {
                return;
            }
            $linked = $this->db->all(
                "SELECT id, people_id FROM {users}
                 WHERE people_id IS NOT NULL AND status = 'active' AND role IN ('teacher', 'supervisor')"
            );
            foreach ($linked as $u) {
                if (!isset($seen[(string) $u['people_id']])) {
                    $this->db->run(
                        "UPDATE {users} SET status = 'suspended', rms_deactivated_at = NOW() WHERE id = ?",
                        [(int) $u['id']]
                    );
                    $stats['deactivated']++;
                }
            }
        });

        return $stats;
    }

    /**
     * กลุ่มเรียน → classrooms จับคู่ด้วย groupCode (RMS ส่งกลุ่มเดิมมาซ้ำทุกภาคเรียน
     * จึงเก็บแถวเดียวต่อกลุ่ม และอัปเดตเฉพาะเมื่อข้อมูลเป็นภาคเรียนเดียวกันหรือใหม่กว่า)
     *
     * @return array{added:int,updated:int,skipped:int,fetched:int}
     */
    public function groups(): array
    {
        $rows = $this->rms->fetch(self::GROUPS);
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'fetched' => count($rows)];

        // จับคู่ครูที่ปรึกษาด้วยชื่อ-นามสกุล (people ไม่มีเลขบัตรให้เทียบ) — สร้าง map ก่อนวนลูป
        $teachers = [];
        foreach ($this->db->all("SELECT id, full_name FROM {users} WHERE role IN ('teacher', 'supervisor', 'admin')") as $t) {
            $teachers[self::nameKey((string) $t['full_name'])] = (int) $t['id'];
        }
        $existing = [];
        foreach ($this->db->all('SELECT id, group_code, academic_year, semester FROM {classrooms} WHERE group_code IS NOT NULL') as $c) {
            $existing[(string) $c['group_code']] = $c;
        }

        $this->db->transaction(function () use ($rows, $teachers, &$existing, &$stats): void {
            foreach ($rows as $g) {
                $year = self::int($g['academicYear'] ?? null);
                $sem = self::int($g['semester'] ?? null);
                $code = self::str($g['groupCode'] ?? '');
                if ($year === null || $sem === null || $code === '' || mb_strlen($code) > 50) {
                    $stats['skipped']++;
                    continue;
                }

                $grade = self::str($g['grade'] ?? '');
                $advisor = self::str(self::str($g['teacherFirstname'] ?? '') . ' ' . self::str($g['teacherLastname'] ?? ''));
                $name = self::str($g['groupName'] ?? '') ?: (self::str($g['groupAbbr'] ?? '') ?: $code);
                $fields = [
                    'name'             => mb_substr($name, 0, 191),
                    'group_abbr'       => self::nullable($g['groupAbbr'] ?? null),
                    'grade'            => $grade !== '' ? mb_substr($grade, 0, 50) : null,
                    'level'            => (str_contains($grade . $name, 'ปวส') || str_contains($grade, 'ส.')) ? 'pvs' : 'pvch',
                    'year_level'       => preg_match('/(\d)/', $grade, $m) === 1 ? (int) $m[1] : 1,
                    'academic_year'    => $year,
                    'semester'         => $sem,
                    'advisor_name'     => $advisor !== '' ? $advisor : null,
                    'advisor_id'       => $teachers[self::nameKey($advisor)] ?? null,
                    'rms_classroom_id' => self::nullable($g['ClassRoomID'] ?? null),
                    'rms_synced_at'    => date('Y-m-d H:i:s'),
                ];

                $current = $existing[$code] ?? null;
                if ($current === null) {
                    $this->db->insert('classrooms', $fields + ['group_code' => $code]);
                    $existing[$code] = ['academic_year' => $year, 'semester' => $sem];
                    $stats['added']++;
                    continue;
                }
                if ($year * 10 + $sem < (int) $current['academic_year'] * 10 + (int) $current['semester']) {
                    $stats['skipped']++; // ข้อมูลภาคเรียนเก่ากว่าที่เก็บไว้
                    continue;
                }
                $this->db->update('classrooms', $fields, ['group_code' => $code]);
                $existing[$code] = ['academic_year' => $year, 'semester' => $sem];
                $stats['updated']++;
            }
        });

        return $stats;
    }

    public function studentCount(): int
    {
        return $this->rms->count(self::STUDENTS);
    }

    /**
     * นักเรียนหนึ่งท่อน → users (role นักเรียน) + student_profiles
     * ชื่อผู้ใช้ = รหัสนักศึกษา, รหัสผ่านตั้งต้น = เลขประจำตัวประชาชน (ตามแบบบัญชีนักเรียนเดิมของระบบ)
     * อัปเดตครั้งถัดไปไม่แตะรหัสผ่านและสถานะบัญชี
     *
     * @return array{added:int,updated:int,skipped:int,fetched:int}
     */
    public function studentBatch(int $offset, int $row): array
    {
        $rows = $this->rms->fetch(self::STUDENTS, ['limit' => $offset . ',' . $row]);
        $institutionId = $this->institutionId();
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'fetched' => count($rows)];

        $classrooms = [];
        foreach ($this->db->all('SELECT id, group_code FROM {classrooms} WHERE group_code IS NOT NULL') as $c) {
            $classrooms[(string) $c['group_code']] = (int) $c['id'];
        }

        $this->db->transaction(function () use ($rows, $institutionId, $classrooms, &$stats): void {
            foreach ($rows as $s) {
                $sid = self::str($s['studentID'] ?? '');
                $code = self::str($s['studentCode'] ?? '') ?: $sid;
                $fullName = self::str(self::str($s['firstname'] ?? '') . ' ' . self::str($s['surname'] ?? ''));
                if ($sid === '' || $fullName === '' || mb_strlen($sid) > 30 || mb_strlen($code) > 64) {
                    $stats['skipped']++;
                    continue;
                }
                $groupCode = self::nullable($s['groupCode'] ?? null);

                $user = $this->db->first(
                    "SELECT id, role FROM {users}
                     WHERE rms_student_id = ? OR (institution_id = ? AND username = ?)
                     ORDER BY rms_student_id = ? DESC LIMIT 1",
                    [$sid, $institutionId, $code, $sid]
                );
                if ($user !== null && $user['role'] !== Roles::STUDENT) {
                    $stats['skipped']++; // ชื่อผู้ใช้ชนกับบัญชีบุคลากร
                    continue;
                }

                $fields = [
                    'full_name'      => mb_substr($fullName, 0, 191),
                    'rms_student_id' => $sid,
                    'classroom_id'   => $groupCode !== null ? ($classrooms[$groupCode] ?? null) : null,
                    'phone'          => self::nullable($s['tel'] ?? null, 32),
                ];

                if ($user === null) {
                    $idcard = preg_replace('/\D/', '', (string) ($s['idcard'] ?? '')) ?: $code;
                    $userId = $this->db->insert('users', $fields + [
                        'username'       => $code,
                        'email'          => $this->freeEmail(self::nullable($s['email'] ?? null), null),
                        'password_hash'  => password_hash($idcard, PASSWORD_DEFAULT),
                        'role'           => Roles::STUDENT,
                        'status'         => 'active',
                        'institution_id' => $institutionId,
                    ]);
                    $stats['added']++;
                } else {
                    $userId = (int) $user['id'];
                    $email = $this->freeEmail(self::nullable($s['email'] ?? null), $userId);
                    if ($email !== null) {
                        $fields['email'] = $email;
                    }
                    $this->db->update('users', $fields, ['id' => $userId]);
                    $stats['updated']++;
                }

                $gpax = self::str($s['gpax'] ?? '');
                $profile = [
                    $userId, $sid, $code,
                    self::nullable($s['gender'] ?? null, 10),
                    $groupCode,
                    self::nullable($s['groupName'] ?? null, 150),
                    self::nullable($s['gradeNameTh'] ?? null, 100),
                    self::nullable($s['majorNameTh'] ?? null, 150),
                    self::nullable($s['studentStatusCode'] ?? null, 10),
                    self::nullable($s['studentStatusName'] ?? null, 100),
                    self::int($s['entranceYear'] ?? null),
                    self::int($s['entranceSemester'] ?? null),
                    is_numeric($gpax) && (float) $gpax >= 0 && (float) $gpax < 100 ? round((float) $gpax, 2) : null,
                ];
                $this->db->run(
                    'INSERT INTO {student_profiles}
                        (user_id, student_id, student_code, gender, group_code, group_name, grade_name, major_name,
                         status_code, status_name, entrance_year, entrance_semester, gpax)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        student_id = VALUES(student_id), student_code = VALUES(student_code), gender = VALUES(gender),
                        group_code = VALUES(group_code), group_name = VALUES(group_name), grade_name = VALUES(grade_name),
                        major_name = VALUES(major_name), status_code = VALUES(status_code), status_name = VALUES(status_name),
                        entrance_year = VALUES(entrance_year), entrance_semester = VALUES(entrance_semester), gpax = VALUES(gpax)',
                    $profile
                );
            }
        });

        return $stats;
    }

    /** @return array{people:int,groups:int,students:int} จำนวนที่โอนมาแล้ว แสดงบนหน้าโอนข้อมูล */
    public function totals(): array
    {
        return [
            'people'   => $this->db->int("SELECT COUNT(*) FROM {users} WHERE people_id IS NOT NULL AND status = 'active'"),
            'groups'   => $this->db->int('SELECT COUNT(*) FROM {classrooms} WHERE group_code IS NOT NULL'),
            'students' => $this->db->int('SELECT COUNT(*) FROM {users} WHERE rms_student_id IS NOT NULL'),
        ];
    }

    /** สถานศึกษาของข้อมูลจาก RMS = วิทยาลัยที่ตั้งชื่อไว้ในหน้าตั้งค่าระบบ */
    private function institutionId(): int
    {
        $name = (string) ($this->settings->get('college_name') ?: $this->config->get('app.college', 'วิทยาลัยเทคนิคร้อยเอ็ด'));

        return $this->institutions->findOrCreateByName($name);
    }

    /** อีเมลที่ใช้ได้ (คอลัมน์ email ห้ามซ้ำทั้งระบบ) — คืน null ถ้ารูปแบบผิดหรือมีบัญชีอื่นใช้อยู่ */
    private function freeEmail(?string $email, ?int $ownerId): ?string
    {
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            return null;
        }
        $taken = $this->db->int(
            'SELECT COUNT(*) FROM {users} WHERE LOWER(email) = LOWER(?) AND id <> ?',
            [$email, $ownerId ?? 0]
        );

        return $taken > 0 ? null : $email;
    }

    /** people_pass ของ RMS คือ md5 ของรหัสผ่าน — รับเฉพาะ hex 32 ตัว คืนเป็นตัวพิมพ์เล็ก */
    private static function md5(mixed $v): ?string
    {
        $v = strtolower(self::str($v));

        return preg_match('/^[0-9a-f]+$/', $v) === 1 && strlen($v) === 32 ? $v : null;
    }

    private static function str(mixed $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
    }

    private static function nullable(mixed $v, int $max = 191): ?string
    {
        $v = self::str($v);
        if ($v === '' || $v === '0000-00-00') {
            return null;
        }

        return mb_substr($v, 0, $max);
    }

    private static function int(mixed $v): ?int
    {
        $v = self::str($v);

        return $v !== '' && is_numeric($v) ? (int) $v : null;
    }

    private static function nameKey(string $name): string
    {
        return preg_replace('/\s+/u', '', $name) ?? '';
    }
}
