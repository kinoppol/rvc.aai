<?php

declare(strict_types=1);

namespace App\Install;

use App\Migration\Migrator;
use App\Support\Paths;
use PDO;

final class Installer
{
    public const VERSION = '1.0.0';

    /**
     * ประกอบไฟล์ตั้งค่าจากข้อมูลที่กรอกในตัวติดตั้ง
     *
     * @param array{db:array,site:array} $input
     */
    public static function buildConfig(array $input, ?string $existingKey = null): array
    {
        return [
            'app' => [
                'name' => $input['site']['name'],
                'college' => $input['site']['college'],
                'url' => rtrim($input['site']['url'], '/'),
                'timezone' => $input['site']['timezone'],
                'key' => $existingKey ?? base64_encode(random_bytes(32)),
                'debug' => false,
                'version' => self::VERSION,
            ],
            'db' => [
                'host' => $input['db']['host'],
                'port' => (int) $input['db']['port'],
                'database' => $input['db']['database'],
                'username' => $input['db']['username'],
                'password' => $input['db']['password'],
                'prefix' => $input['db']['prefix'],
            ],
        ];
    }

    /**
     * ตารางของระบบนี้ที่มีอยู่จริงในฐานข้อมูล พร้อมจำนวนแถว
     * ใช้แสดงให้ผู้ติดตั้งเห็นก่อนตัดสินใจล้างข้อมูล
     *
     * @return array<string,int>
     */
    public static function existingAppTables(PDO $db, Migrator $migrator): array
    {
        $declared = $migrator->declaredTables();
        $present = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $result = [];
        foreach ($present as $table) {
            if (in_array($table, $declared, true)) {
                $result[$table] = (int) $db->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
            }
        }

        return $result;
    }

    /**
     * ลบเฉพาะตารางที่ระบบนี้เป็นเจ้าของ ตารางอื่นในฐานข้อมูลเดียวกันจะไม่ถูกแตะ
     *
     * @return list<string> ตารางที่ถูกลบ
     */
    public static function dropAppTables(PDO $db, Migrator $migrator): array
    {
        $tables = array_keys(self::existingAppTables($db, $migrator));

        if ($tables === []) {
            return [];
        }

        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
            }
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $tables;
    }

    /**
     * สร้างบัญชีผู้ดูแลระบบ ถ้ามีชื่อผู้ใช้นี้อยู่แล้วจะอัปเดตรหัสผ่านและสิทธิ์ให้แทน
     *
     * @param array{username:string,password:string,full_name:string,email:?string} $admin
     */
    public static function createAdmin(PDO $db, string $prefix, array $admin): int
    {
        $table = $prefix . 'users';
        $hash = password_hash($admin['password'], PASSWORD_DEFAULT);

        $stmt = $db->prepare(sprintf('SELECT id FROM `%s` WHERE username = ?', $table));
        $stmt->execute([$admin['username']]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $update = $db->prepare(sprintf(
                'UPDATE `%s` SET password_hash = ?, full_name = ?, email = ?, role = \'admin\', status = \'active\' WHERE id = ?',
                $table
            ));
            $update->execute([$hash, $admin['full_name'], $admin['email'] ?: null, $id]);

            return (int) $id;
        }

        $insert = $db->prepare(sprintf(
            'INSERT INTO `%s` (username, email, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, \'admin\', \'active\')',
            $table
        ));
        $insert->execute([$admin['username'], $admin['email'] ?: null, $hash, $admin['full_name']]);

        return (int) $db->lastInsertId();
    }

    /** ค่าตั้งต้นของระบบ — เขียนทับเฉพาะค่าที่ยังไม่เคยมี */
    public static function seedSettings(PDO $db, string $prefix, array $site): void
    {
        $defaults = [
            ['site_name', $site['name'], 'string', 'general'],
            ['college_name', $site['college'], 'string', 'general'],
            ['academic_year', (string) $site['academic_year'], 'integer', 'academic'],
            ['semester', (string) $site['semester'], 'integer', 'academic'],
            ['ai_monthly_quota', '60', 'integer', 'ai'],
            ['ai_require_review', '1', 'boolean', 'ai'],
            ['allow_teacher_byok', '1', 'boolean', 'ai'],
        ];

        $stmt = $db->prepare(sprintf(
            'INSERT INTO `%s` (name, value, type, group_name) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            $prefix . 'settings'
        ));

        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    }

    /** สร้างภาคเรียนปัจจุบันให้พร้อมใช้ทันทีหลังติดตั้ง */
    public static function seedCurrentTerm(PDO $db, string $prefix, int $year, int $semester): void
    {
        $stmt = $db->prepare(sprintf(
            'INSERT INTO `%s` (academic_year, semester, name, is_current) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE is_current = 1',
            $prefix . 'academic_terms'
        ));
        $stmt->execute([$year, $semester, sprintf('ภาคเรียนที่ %d / %d', $semester, $year)]);
    }

    public static function writeLock(array $meta): void
    {
        file_put_contents(
            Paths::lockFile(),
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    public static function readLock(): ?array
    {
        if (!is_file(Paths::lockFile())) {
            return null;
        }

        $data = json_decode((string) file_get_contents(Paths::lockFile()), true);

        return is_array($data) ? $data : null;
    }

    public static function isLocked(): bool
    {
        return is_file(Paths::lockFile());
    }
}
