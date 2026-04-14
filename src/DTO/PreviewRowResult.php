<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class PreviewRowResult
{
    /**
     * @param array<int, ValidationError> $errors
     * @param array<string, mixed> $normalized
     * @param array<string, mixed>|null $preview
     */
    public function __construct(
        public readonly int $line,
        public readonly string $status,
        public readonly array $errors,
        public readonly array $normalized,
        public readonly ?array $preview = null
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
            'preview' => $this->preview,
        ];
    }
}
