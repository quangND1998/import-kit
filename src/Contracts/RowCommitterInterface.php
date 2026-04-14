<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

interface RowCommitterInterface
{
    /**
     * @param array<string, mixed> $mappedRow
     */
    public function commit(array $mappedRow): void;
}
