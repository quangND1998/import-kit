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
        public readonly ?int $customFieldStartColumn = null,
        public readonly string $customFieldPattern = '/\|\s*(?<id>[A-Za-z0-9_-]+)\s*$/',
        public readonly string $normalizeMode = 'snake'
    ) {
        if ($this->headerRowIndex < 1) {
            throw new InvalidArgumentException('headerRowIndex must be >= 1.');
        }

        if ($this->customFieldStartColumn !== null && $this->customFieldStartColumn < 1) {
            throw new InvalidArgumentException('customFieldStartColumn must be >= 1 when provided.');
        }
    }
}

