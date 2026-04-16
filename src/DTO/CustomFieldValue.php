<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class CustomFieldValue
{
    /**
     * @param mixed $value
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $customFieldId,
        public readonly mixed $value,
        public readonly ?int $columnIndex = null,
        public readonly ?string $columnKey = null,
        public readonly array $meta = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'custom_field_id' => $this->customFieldId,
            'value' => $this->value,
            'column_index' => $this->columnIndex,
            'column_key' => $this->columnKey,
            'meta' => $this->meta,
        ];
    }
}

