<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;

interface ContextAwareRowMapperInterface extends RowMapperInterface
{
    /**
     * @param array<string, mixed> $validatedRow
     * @return array<string, mixed>
     */
    public function mapWithContext(array $validatedRow, ImportRunContext $context): array;
}

