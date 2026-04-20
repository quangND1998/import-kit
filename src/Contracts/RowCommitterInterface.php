<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;

interface RowCommitterInterface
{
    /**
     * @param array<string, mixed> $mappedRow
     */
    public function commit(array $mappedRow, ImportRunContext $context): void;
}
