<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;

interface ContextAwareRowCommitterInterface extends RowCommitterInterface
{
    /**
     * @param array<string, mixed> $mappedRow
     */
    public function commitWithContext(array $mappedRow, ImportRunContext $context): void;
}

