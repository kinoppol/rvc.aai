<?php

declare(strict_types=1);

namespace App\Migration;

use App\Support\Paths;
use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    private readonly string $path;

    public function __construct(
        private readonly PDO $db,
        private readonly string $prefix = '',
        ?string $path = null,
    ) {
        $this->path = $path ?? Paths::migrations();
    }

    public function repositoryTable(): string
    {
        return $this->prefix . 'migrations';
    }

    public function repositoryExists(): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$this->repositoryTable()]);

        return $stmt->fetchColumn() !== false;
    }

    public function ensureRepository(): void
    {
        $this->db->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(191) NOT NULL,
                `batch` INT UNSIGNED NOT NULL,
                `description` VARCHAR(255) NOT NULL DEFAULT \'\',
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `ran_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->repositoryTable()
        ));
    }

    /** @return array<string,MigrationFile> เรียงตามชื่อไฟล์ */
    public function files(): array
    {
        $files = glob($this->path . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $result = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $result[$name] = new MigrationFile($name, $file, $this->prefix);
        }

        return $result;
    }

    /** @return array<string,array{migration:string,batch:int,description:string,duration_ms:int,ran_at:string}> */
    public function records(): array
    {
        if (!$this->repositoryExists()) {
            return [];
        }

        $rows = $this->db->query(sprintf(
            'SELECT migration, batch, description, duration_ms, ran_at FROM `%s` ORDER BY migration',
            $this->repositoryTable()
        ))->fetchAll();

        return array_column($rows, null, 'migration');
    }

    /**
     * @return array{
     *     ran: list<array{name:string,description:string,batch:int,duration_ms:int,ran_at:string}>,
     *     pending: list<array{name:string,description:string}>,
     *     missing: list<array{name:string,batch:int,ran_at:string}>,
     *     lastBatch: int
     * }
     */
    public function status(): array
    {
        $files = $this->files();
        $records = $this->records();

        $ran = [];
        $pending = [];
        $missing = [];

        foreach ($files as $name => $file) {
            if (isset($records[$name])) {
                $ran[] = [
                    'name' => $name,
                    'description' => $this->safeDescription($file),
                    'batch' => (int) $records[$name]['batch'],
                    'duration_ms' => (int) $records[$name]['duration_ms'],
                    'ran_at' => (string) $records[$name]['ran_at'],
                ];
            } else {
                $pending[] = ['name' => $name, 'description' => $this->safeDescription($file)];
            }
        }

        foreach ($records as $name => $record) {
            if (!isset($files[$name])) {
                $missing[] = [
                    'name' => $name,
                    'batch' => (int) $record['batch'],
                    'ran_at' => (string) $record['ran_at'],
                ];
            }
        }

        return [
            'ran' => $ran,
            'pending' => $pending,
            'missing' => $missing,
            'lastBatch' => $records === [] ? 0 : max(array_map(static fn ($r) => (int) $r['batch'], $records)),
        ];
    }

    public function hasPending(): bool
    {
        return $this->status()['pending'] !== [];
    }

    /**
     * รัน migration ที่ยังไม่เคยรัน เรียงตามชื่อไฟล์ หยุดทันทีเมื่อเจอข้อผิดพลาด
     *
     * @return list<array{name:string,description:string,direction:string,ok:bool,duration_ms:int,error:?string}>
     */
    public function migrate(): array
    {
        $this->ensureRepository();
        $status = $this->status();

        if ($status['pending'] === []) {
            return [];
        }

        $files = $this->files();
        $batch = $status['lastBatch'] + 1;
        $steps = [];

        $this->lock();
        try {
            foreach ($status['pending'] as $item) {
                $step = $this->runOne($files[$item['name']], 'up');
                $steps[] = $step;

                if (!$step['ok']) {
                    break;
                }

                $this->recordRan($item['name'], $step['description'], $batch, $step['duration_ms']);
            }
        } finally {
            $this->unlock();
        }

        return $steps;
    }

    /** ย้อนกลับ batch ล่าสุด */
    public function rollback(): array
    {
        $status = $this->status();

        if ($status['lastBatch'] === 0) {
            return [];
        }

        return $this->rollbackNames($this->namesInBatch($status['lastBatch']));
    }

    /** ย้อนกลับทั้งหมด */
    public function reset(): array
    {
        $names = array_keys($this->records());
        rsort($names, SORT_STRING);

        return $this->rollbackNames($names);
    }

    /** @return list<string> ชื่อ migration ของ batch ที่ระบุ เรียงย้อนลำดับการรัน */
    public function namesInBatch(int $batch): array
    {
        $stmt = $this->db->prepare(sprintf(
            'SELECT migration FROM `%s` WHERE batch = ? ORDER BY migration DESC',
            $this->repositoryTable()
        ));
        $stmt->execute([$batch]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @param list<string> $names */
    private function rollbackNames(array $names): array
    {
        $files = $this->files();
        $steps = [];

        $this->lock();
        try {
            foreach ($names as $name) {
                if (!isset($files[$name])) {
                    $steps[] = [
                        'name' => $name,
                        'description' => '',
                        'direction' => 'down',
                        'ok' => false,
                        'duration_ms' => 0,
                        'error' => 'ไม่พบไฟล์ migration นี้แล้ว จึงย้อนกลับให้อัตโนมัติไม่ได้',
                    ];
                    break;
                }

                $step = $this->runOne($files[$name], 'down');
                $steps[] = $step;

                if (!$step['ok']) {
                    break;
                }

                $this->forget($name);
            }
        } finally {
            $this->unlock();
        }

        return $steps;
    }

    /** @return list<string> คำสั่ง SQL ที่ migration นี้จะรัน โดยไม่แตะฐานข้อมูลจริง */
    public function preview(string $name, string $direction = 'up'): array
    {
        $files = $this->files();

        if (!isset($files[$name])) {
            throw new RuntimeException('ไม่พบ migration ชื่อ ' . $name);
        }

        $runner = new DryRunner();
        $migration = $files[$name]->instance();
        $direction === 'down' ? $migration->down($runner) : $migration->up($runner);

        return $runner->statements();
    }

    /**
     * ชื่อตารางทั้งหมดที่ระบบนี้เป็นเจ้าของ ดึงจากคำสั่ง CREATE TABLE ของ migration ทุกไฟล์
     * ใช้ตอนติดตั้งซ้ำแบบล้างข้อมูล เพื่อไม่ให้ไปลบตารางของระบบอื่นที่ใช้ฐานข้อมูลร่วมกัน
     *
     * @return list<string>
     */
    public function declaredTables(): array
    {
        $tables = [$this->repositoryTable()];

        foreach ($this->files() as $name => $file) {
            try {
                $sqls = $this->preview($name, 'up');
            } catch (Throwable) {
                continue;
            }

            foreach ($sqls as $sql) {
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m) === 1) {
                    $tables[] = $m[1];
                }
            }
        }

        return array_values(array_unique($tables));
    }

    private function runOne(MigrationFile $file, string $direction): array
    {
        $description = $this->safeDescription($file);
        $started = hrtime(true);

        try {
            $migration = $file->instance();
            $runner = new LiveRunner($this->db);
            $direction === 'down' ? $migration->down($runner) : $migration->up($runner);
            $error = null;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'name' => $file->name,
            'description' => $description,
            'direction' => $direction,
            'ok' => $error === null,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $error,
        ];
    }

    private function safeDescription(MigrationFile $file): string
    {
        try {
            return $file->description();
        } catch (Throwable) {
            return '';
        }
    }

    private function recordRan(string $name, string $description, int $batch, int $durationMs): void
    {
        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO `%s` (migration, batch, description, duration_ms, ran_at) VALUES (?, ?, ?, ?, NOW())',
            $this->repositoryTable()
        ));
        $stmt->execute([$name, $batch, mb_substr($description, 0, 255), $durationMs]);
    }

    private function forget(string $name): void
    {
        $stmt = $this->db->prepare(sprintf('DELETE FROM `%s` WHERE migration = ?', $this->repositoryTable()));
        $stmt->execute([$name]);
    }

    private function lockName(): string
    {
        return 'rvc_learn_migrate_' . md5($this->prefix . $this->path);
    }

    private function lock(): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([$this->lockName()]);

        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('มีการปรับปรุงฐานข้อมูลอื่นกำลังทำงานอยู่ กรุณารอสักครู่แล้วลองใหม่');
        }
    }

    private function unlock(): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$this->lockName()]);
    }
}
