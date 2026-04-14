<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ValidationResult
{
    /**
     * @param array<int, ValidationError> $errors
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $errors = []
    ) {
    }

    public static function ok(): self
    {
        return new self(true, []);
    }

    /**
     * @param array<int, ValidationError> $errors
     */
    public static function fail(array $errors): self
    {
        return new self(false, $errors);
    }
}
