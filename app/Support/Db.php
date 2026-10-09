<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * ตัวช่วยเรียกฐานข้อมูลแบบสั้น ๆ พร้อมเติมคำนำหน้าตารางให้อัตโนมัติ
 *
 * ใช้เครื่องหมาย {table} ในคำสั่ง SQL แล้วระบบจะแทนด้วยคำนำหน้า เช่น
 *   $db->all('SELECT * FROM {courses} WHERE teacher_id = ?', [$id])
 */
final class Db
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = '',
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /** @param array<int|string,mixed> $params @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($this->expand($sql));
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($this->expand($sql));
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($this->expand($sql));
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<int|string,mixed> $params */
    public function int(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($this->expand($sql));
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * เพิ่มแถวใหม่จาก key => value แล้วคืนค่า id
     *
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', array_map(static fn (string $c): string => "`$c`", $columns)),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * แก้ไขแถวตามเงื่อนไข where (key => value เท่านั้น)
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = :set_$c", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn (string $c): string => "`$c` = :where_$c", array_keys($where)));

        $params = [];
        foreach ($data as $key => $val) {
            $params["set_$key"] = $val;
        }
        foreach ($where as $key => $val) {
            $params["where_$key"] = $val;
        }

        $stmt = $this->pdo->prepare(sprintf('UPDATE `%s` SET %s WHERE %s', $this->table($table), $set, $cond));
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $work($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function expand(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $m): string => '`' . $this->table($m[1]) . '`',
            $sql
        );
    }
}
