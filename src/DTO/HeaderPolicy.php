<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

use InvalidArgumentException;

final class HeaderPolicy
{
    /**
     * @param array<int, string> $requiredHeaders
     * @param array<int, string> $optionalHeaders
     * @param array<int, string> $strictCoreColumns
     */
    public function __construct(
        public readonly int $headerRowIndex = 1,
        public readonly array $requiredHeaders = [],
        public readonly array $optionalHeaders = [],
        public readonly bool $strictOrder = false,
        public readonly array $strictCoreColumns = [],
        public readonly string $normalizeMode = 'snake'
    ) {
        if ($this->headerRowIndex < 1) {
            throw new InvalidArgumentException('headerRowIndex must be >= 1.');
        }
    }
}

