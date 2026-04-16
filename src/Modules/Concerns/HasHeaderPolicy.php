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
        ?int $customFieldStartColumn = null,
        string $customFieldPattern = '/\|\s*(?<id>[A-Za-z0-9_-]+)\s*$/',
        string $normalizeMode = 'snake'
    ): HeaderPolicy {
        return new HeaderPolicy(
            headerRowIndex: $row,
            requiredHeaders: $requiredHeaders,
            optionalHeaders: [],
            strictOrder: $strictOrder,
            strictCoreColumns: $strictCoreColumns,
            customFieldStartColumn: $customFieldStartColumn,
            customFieldPattern: $customFieldPattern,
            normalizeMode: $normalizeMode
        );
    }
}

