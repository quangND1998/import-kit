<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

interface RowMapperInterface
{
    /**
     * @param array<string, mixed> $validatedRow
     * @return array<string, mixed>
     */
    public function map(array $validatedRow): array;
}
