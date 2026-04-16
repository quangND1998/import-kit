<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class TemplateValidationError
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $field = null,
        public readonly array $meta = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'field' => $this->field,
            'meta' => $this->meta,
        ];
    }
}

