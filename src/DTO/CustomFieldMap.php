<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class CustomFieldMap
{
    /**
     * @param array<string, string> $columnToField
     * @param array<string, array<string, mixed>> $fieldMeta
     */
    public function __construct(
        public readonly array $columnToField = [],
        public readonly array $fieldMeta = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'column_to_field' => $this->columnToField,
            'field_meta' => $this->fieldMeta,
        ];
    }
}

