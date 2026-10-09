<?php

declare(strict_types=1);

namespace App\AI;

/**
 * เส้นทาง AI ที่เลือกได้สำหรับงานหนึ่งครั้ง — บอกว่าใช้แหล่งไหน ข้อความ chip และเวลารอโดยประมาณ
 */
final class AiRoute
{
    /** @param 'college'|'byok' $source */
    public function __construct(
        public readonly AiProvider $provider,
        public readonly string $source,
        public readonly string $chip,
        public readonly string $wait,
        public readonly bool $countsQuota,
        public readonly string $model = '',
    ) {
    }
}
