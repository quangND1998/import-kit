<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class StoredFile
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $disk,
        public readonly string $path,
        public readonly array $meta = []
    ) {
    }
}
