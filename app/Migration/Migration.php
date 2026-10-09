<?php

declare(strict_types=1);

namespace App\Migration;

abstract class Migration
{
    public function __construct(protected readonly string $prefix = '')
    {
    }

    /** คำอธิบายภาษาไทยที่จะแสดงในหน้าจัดการฐานข้อมูล */
    abstract public function description(): string;

    abstract public function up(Runner $db): void;

    abstract public function down(Runner $db): void;

    protected function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /** ท้าย CREATE TABLE ที่ใช้ร่วมกันทุกตาราง */
    protected function options(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
