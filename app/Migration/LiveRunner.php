<?php

declare(strict_types=1);

namespace App\Migration;

use PDO;

final class LiveRunner implements Runner
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function exec(string $sql): void
    {
        $this->db->exec($sql);
    }
}
