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

    /** @return array<string,mixed>|null ผู้ให้บริการหลักของส่วนกลาง — ตัวที่เปิดอยู่ก่อนตัวที่ผู้ดูแลปิดไว้ */
    public function defaultEndpoint(): ?array
    {
        return $this->db->first(
            'SELECT * FROM {ai_endpoints} ORDER BY is_active DESC, is_default DESC, id LIMIT 1'
        );
    }

    /** @return list<array<string,mixed>> */
    public function endpoints(): array
    {
        return $this->db->all('SELECT * FROM {ai_endpoints} ORDER BY is_default DESC, id');
    }

    /** @return list<array<string,mixed>> ผู้ให้บริการส่วนกลางที่ผู้ดูแลเปิดไว้ — ตัวหลักก่อน */
    public function activeEndpoints(): array
    {
        return $this->db->all('SELECT * FROM {ai_endpoints} WHERE is_active = 1 ORDER BY is_default DESC, id');
    }

    /** @return array<string,mixed>|null */
    public function endpoint(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {ai_endpoints} WHERE id = ?', [$id]);
    }

    public function deleteEndpoint(int $id): void
    {
        $wasDefault = $this->db->int('SELECT is_default FROM {ai_endpoints} WHERE id = ?', [$id]) === 1;
        $this->db->run('DELETE FROM {ai_endpoints} WHERE id = ?', [$id]);

        // ลบตัวหลักไป — ให้ตัวที่เหลือตัวแรกขึ้นเป็นตัวหลักแทน
        if ($wasDefault) {
            $next = $this->db->int('SELECT id FROM {ai_endpoints} ORDER BY is_active DESC, id LIMIT 1');
            if ($next > 0) {
                $this->setDefaultEndpoint($next);
            }
        }
    }

    public function setEndpointActive(int $id, bool $active): void
    {
        $this->db->update('ai_endpoints', ['is_active' => $active ? 1 : 0], ['id' => $id]);
    }

    /** ตั้งเป็นผู้ให้บริการหลักของส่วนกลาง (มีได้ตัวเดียว) */
    public function setDefaultEndpoint(int $id): void
    {
        $this->db->run('UPDATE {ai_endpoints} SET is_default = (id = ?)', [$id]);
    }

    /**
     * โมเดลที่เปิดใช้ของผู้ให้บริการหนึ่งราย (ส่วนกลางหรือของครู) — ตัวแรกคือโมเดลหลัก
     * แถวที่ยังไม่เคยเลือกหลายโมเดลใช้คอลัมน์ model เดิม · คืน [''] = ให้ปลายทางเลือกโมเดลเอง
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    public static function enabledModels(array $row): array
    {
        $list = json_decode((string) ($row['models_enabled'] ?? ''), true);
        $models = [];
        foreach (is_array($list) ? $list : [] as $m) {
            $m = trim((string) $m);
            if ($m !== '' && !in_array($m, $models, true)) {
                $models[] = $m;
            }
        }

        if ($models === []) {
            $models = [trim((string) ($row['model'] ?? ''))];
        }

        return $models;
    }

    /**
     * โมเดลของส่วนกลางที่ผู้ดูแลตั้งให้ใช้ได้ไม่จำกัด (เช่น โมเดลฟรี) — งานที่ใช้โมเดลเหล่านี้ไม่นับโควตาของครู
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    public static function unlimitedModels(array $row): array
    {
        $list = json_decode((string) ($row['models_unlimited'] ?? ''), true);

        return array_values(array_filter(
            array_map(static fn (mixed $m): string => trim((string) $m), is_array($list) ? $list : []),
            static fn (string $m): bool => $m !== ''
        ));
    }

    /**
     * รายชื่อโมเดลไม่จำกัดสำหรับบันทึก — เก็บเฉพาะตัวที่เปิดใช้อยู่จริง
     *
     * @param list<mixed> $raw
     * @param list<string> $enabled
     */
    public static function unlimitedField(array $raw, array $enabled): ?string
    {
        $models = array_values(array_intersect($enabled, array_map(static fn (mixed $m): string => trim((string) $m), $raw)));

        return $models === [] ? null : (string) json_encode($models, JSON_UNESCAPED_UNICODE);
    }

    /**
     * ทำรายชื่อโมเดลจากฟอร์มให้สะอาด แล้วคืนค่าสำหรับบันทึก (model = ตัวหลัก, models_enabled = JSON)
     *
     * @param list<mixed> $raw
     * @return array{model:string,models_enabled:?string}
     */
    public static function modelFields(array $raw): array
    {
        $models = [];
        foreach ($raw as $m) {
            $m = mb_substr(trim((string) $m), 0, 191);
            if ($m !== '' && !in_array($m, $models, true)) {
                $models[] = $m;
            }
        }
        $models = array_slice($models, 0, 50);

        return [
            'model' => $models[0] ?? '',
            'models_enabled' => $models === [] ? null : (string) json_encode($models, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array<string,mixed>|null คีย์ที่ครูเชื่อมไว้และใช้งานได้ (ตัวล่าสุด) */
    public function activeKeyForUser(int $userId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {user_api_keys} WHERE user_id = ? AND status = \'active\' ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> คีย์ทั้งหมดของครูที่ยังใช้งานอยู่ — ล่าสุดก่อน */
    public function activeKeysForUser(int $userId): array
    {
        return $this->db->all(
            'SELECT * FROM {user_api_keys} WHERE user_id = ? AND status = \'active\' ORDER BY id DESC',
            [$userId]
        );
    }

    /** @return array<string,mixed>|null คีย์ของครูคนนี้เท่านั้น (กันครูอ้าง id คีย์ของคนอื่น) */
    public function keyForUser(int $userId, int $keyId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {user_api_keys} WHERE id = ? AND user_id = ? AND status = \'active\'',
            [$keyId, $userId]
        );
    }

    /** @param array<string,mixed> $data */
    public function updateKey(int $userId, int $keyId, array $data): void
    {
        $this->db->update('user_api_keys', $data, ['id' => $keyId, 'user_id' => $userId]);
    }

    public function disableKey(int $userId, int $keyId): void
    {
        $this->db->run(
            'UPDATE {user_api_keys} SET status = \'disabled\' WHERE id = ? AND user_id = ?',
            [$keyId, $userId]
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

    /**
     * เพิ่มคีย์ใหม่ของครู — เก็บได้หลายคีย์ (หลายผู้ให้บริการ) โดยไม่ปิดคีย์เดิม
     *
     * @param array<string,mixed> $data
     */
    public function saveKey(int $userId, array $data): int
    {
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
     * บันทึกผู้ให้บริการ AI ส่วนกลาง (มีได้หลายราย) แล้วคืน id
     * รายแรกที่เพิ่มเข้ามาเป็นตัวหลักให้อัตโนมัติ
     *
     * @param array<string,mixed> $data
     */
    public function saveEndpoint(?int $id, array $data): int
    {
        if ($id !== null && $this->db->int('SELECT COUNT(*) FROM {ai_endpoints} WHERE id = ?', [$id]) > 0) {
            $this->db->update('ai_endpoints', $data, ['id' => $id]);

            return $id;
        }

        $isFirst = $this->db->int('SELECT COUNT(*) FROM {ai_endpoints} WHERE is_default = 1') === 0;

        return $this->db->insert('ai_endpoints', $data + ['is_default' => $isFirst ? 1 : 0]);
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
