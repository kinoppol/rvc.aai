<?php

declare(strict_types=1);

namespace App\Migration;

final class DryRunner implements Runner
{
    /** @var list<string> */
    private array $statements = [];

    public function exec(string $sql): void
    {
        $this->statements[] = trim($sql);
    }

    /** @return list<string> */
    public function statements(): array
    {
        return $this->statements;
    }
}
