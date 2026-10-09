<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * เครื่อง AI ของส่วนกลาง คีย์ของครู โควตา คิวงาน และบันทึกการใช้งาน
 */
final class AiRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null เครื่อง AI หลักของวิทยาลัย */
    public function defaultEndpoint(): ?array
    {
        return $this->db->first(
            'SELECT * FROM {ai_endpoints} WHERE is_default = 1 ORDER BY id LIMIT 1'
        ) ?? $this->db->first('SELECT * FROM {ai_endpoints} ORDER BY id LIMIT 1');
    }

    /** @return list<array<string,mixed>> */
    public function endpoints(): array
    {
        return $this->db->all('SELECT * FROM {ai_endpoints} ORDER BY is_default DESC, id');
    }

    /** @return array<string,mixed>|null คีย์ที่ครูเชื่อมไว้และใช้งานได้ */
    public function activeKeyForUser(int $userId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {user_api_keys} WHERE user_id = ? AND status = \'active\' ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    /** @return array<string,mixed>|null คีย์ล่าสุดไม่ว่าสถานะใด */
    public function latestKeyForUser(int $userId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {user_api_keys} WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    /** @param array<string,mixed> $data */
    public function saveKey(int $userId, array $data): int
    {
        $this->db->run('UPDATE {user_api_keys} SET status = \'disabled\' WHERE user_id = ?', [$userId]);

        return $this->db->insert('user_api_keys', $data + ['user_id' => $userId]);
    }

    public function markKeyStatus(int $keyId, string $status): void
    {
        $this->db->update('user_api_keys', ['status' => $status], ['id' => $keyId]);
    }

    public function disconnectUser(int $userId): void
    {
        $this->db->run('UPDATE {user_api_keys} SET status = \'disabled\' WHERE user_id = ?', [$userId]);
    }

    public function touchKeyUsed(int $keyId): void
    {
        $this->db->run('UPDATE {user_api_keys} SET last_used_at = NOW() WHERE id = ?', [$keyId]);
    }

    // ---- โควตา ----

    /** @return array{limit:int,used:int,left:int,pct:int} */
    public function quota(int $userId, string $period, int $defaultLimit): array
    {
        $row = $this->db->first(
            'SELECT monthly_limit, used_count FROM {ai_quotas} WHERE user_id = ? AND period = ?',
            [$userId, $period]
        );

        $limit = max(1, (int) ($row['monthly_limit'] ?? $defaultLimit));
        $used = (int) ($row['used_count'] ?? 0);

        return [
            'limit' => $limit,
            'used' => $used,
            'left' => max(0, $limit - $used),
            'pct' => (int) round(min(1, $used / $limit) * 100),
        ];
    }

    public function consumeQuota(int $userId, string $period, int $defaultLimit): void
    {
        $this->db->run(
            'INSERT INTO {ai_quotas} (user_id, period, monthly_limit, used_count) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE used_count = used_count + 1',
            [$userId, $period, $defaultLimit]
        );
    }

    // ---- คิวงาน ----

    /**
     * บันทึกการตั้งค่าเครื่อง AI ส่วนกลาง (มีได้ตัวเดียวเป็นค่าเริ่มต้น) แล้วคืน id
     *
     * @param array<string,mixed> $data
     */
    public function saveEndpoint(?int $id, array $data): int
    {
        if ($id !== null && $this->db->int('SELECT COUNT(*) FROM {ai_endpoints} WHERE id = ?', [$id]) > 0) {
            $this->db->update('ai_endpoints', $data, ['id' => $id]);

            return $id;
        }

        return $this->db->insert('ai_endpoints', $data + ['is_default' => 1]);
    }

    /** อัปเดตผลการตรวจสอบสถานะเครื่องส่วนกลาง */
    public function markEndpointChecked(int $id, string $status): void
    {
        $this->db->update('ai_endpoints', [
            'status' => $status,
            'last_checked_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function createJob(array $data): int
    {
        return $this->db->insert('ai_jobs', $data + ['status' => 'queued']);
    }

    /** @param array<string,mixed> $data */
    public function updateJob(int $jobId, array $data): void
    {
        $this->db->update('ai_jobs', $data, ['id' => $jobId]);
    }

    /** @return array<string,mixed>|null */
    public function findJob(int $jobId): ?array
    {
        return $this->db->first('SELECT * FROM {ai_jobs} WHERE id = ?', [$jobId]);
    }

    public function queueLength(): int
    {
        return $this->db->int('SELECT COUNT(*) FROM {ai_jobs} WHERE status IN (\'queued\',\'running\')');
    }

    public function logUsage(int $userId, ?int $jobId, string $source, string $model, bool $ok): void
    {
        $this->db->insert('ai_usage_logs', [
            'user_id' => $userId,
            'job_id' => $jobId,
            'source' => $source,
            'model' => $model,
            'succeeded' => $ok ? 1 : 0,
        ]);
    }
}
