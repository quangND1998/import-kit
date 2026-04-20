<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;

interface RowMapperInterface
{
    /**
     * @param array<string, mixed> $validatedRow
     * @return array<string, mixed>
     */
    public function map(array $validatedRow, ImportRunContext $context): array;
}
