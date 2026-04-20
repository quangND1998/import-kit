<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;

interface RowParserInterface
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function parse(array $row, ImportRunContext $context): array;
}
