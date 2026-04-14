<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ImportResultRowData
{
    /**
     * @param array<int, ValidationError> $errors
     * @param array<string, mixed> $normalized
     * @param array<string, mixed>|null $mapped
     */
    public function __construct(
        public readonly int $line,
        public readonly string $status,
        public readonly array $errors = [],
        public readonly array $normalized = [],
        public readonly ?array $mapped = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'status' => $this->status,
            'errors' => array_map(static fn (ValidationError $error) => $error->toArray(), $this->errors),
            'normalized' => $this->normalized,
            'mapped' => $this->mapped,
        ];
    }
}
