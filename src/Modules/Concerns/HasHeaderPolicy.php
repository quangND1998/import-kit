<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Modules\Concerns;

use Vendor\ImportKit\DTO\HeaderPolicy;

trait HasHeaderPolicy
{
    protected function makeHeaderPolicy(
        int $row,
        bool $strictOrder = false,
        array $strictCoreColumns = [],
        array $requiredHeaders = [],
        string $normalizeMode = 'snake'
    ): HeaderPolicy {
        return new HeaderPolicy(
            headerRowIndex: $row,
            requiredHeaders: $requiredHeaders,
            optionalHeaders: [],
            strictOrder: $strictOrder,
            strictCoreColumns: $strictCoreColumns,
            normalizeMode: $normalizeMode
        );
    }
}

