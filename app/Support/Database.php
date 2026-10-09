<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class Database
{
    public const MIN_MARIADB = '10.4';
    public const MIN_MYSQL = '5.7';

    /** @param array{host:string,port:int|string,database:string,username:string,password:string} $c */
    public static function connect(array $c): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['host'],
            (int) ($c['port'] ?? 3306),
            $c['database']
        );

        return self::pdo($dsn, $c);
    }

    /** เชื่อมต่อโดยไม่ระบุฐานข้อมูล ใช้ตอนตรวจสอบเวอร์ชันหรือสร้างฐานข้อมูลใหม่ */
    public static function connectServer(array $c): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $c['host'],
            (int) ($c['port'] ?? 3306)
        );

        return self::pdo($dsn, $c);
    }

    private static function pdo(string $dsn, array $c): PDO
    {
        return new PDO($dsn, (string) $c['username'], (string) ($c['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ]);
    }

    public static function version(PDO $db): string
    {
        return (string) $db->query('SELECT VERSION()')->fetchColumn();
    }

    /** @return array{name:string,version:string,supported:bool,message:string} */
    public static function inspectServer(PDO $db): array
    {
        $raw = self::version($db);
        $isMaria = stripos($raw, 'mariadb') !== false;
        $number = preg_match('/(\d+\.\d+\.\d+)/', $raw, $m) === 1 ? $m[1] : '0.0.0';
        $name = $isMaria ? 'MariaDB' : 'MySQL';
        $minimum = $isMaria ? self::MIN_MARIADB : self::MIN_MYSQL;
        $supported = version_compare($number, $minimum, '>=');

        return [
            'name' => $name,
            'version' => $number,
            'supported' => $supported,
            'message' => $supported
                ? sprintf('%s %s ใช้งานได้', $name, $number)
                : sprintf('ต้องการ %s %s ขึ้นไป แต่เซิร์ฟเวอร์นี้เป็น %s %s', $name, $minimum, $name, $number),
        ];
    }

    public static function databaseExists(PDO $server, string $name): bool
    {
        $stmt = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$name]);

        return $stmt->fetchColumn() !== false;
    }

    public static function createDatabase(PDO $server, string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('ชื่อฐานข้อมูลใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ ตัวเลข และขีดล่าง');
        }

        $server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $name
        ));
    }

    /** @return list<string> รายชื่อตารางทั้งหมดในฐานข้อมูลปัจจุบัน */
    public static function tables(PDO $db): array
    {
        return $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public static function countRows(PDO $db, string $table): int
    {
        return (int) $db->query(sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '', $table)))->fetchColumn();
    }
}
