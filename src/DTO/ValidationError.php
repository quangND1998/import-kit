<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ValidationError
{
    public function __construct(
        public readonly string $field,
        public readonly string $code,
        public readonly string $message
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
