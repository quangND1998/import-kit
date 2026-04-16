<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class CustomFieldDefinition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $title = null,
        public readonly ?string $dataType = null,
        public readonly bool $active = true,
        public readonly array $meta = []
    ) {
    }
}

