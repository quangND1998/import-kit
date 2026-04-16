<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class TemplateValidationResult
{
    /**
     * @param array<int, TemplateValidationError> $errors
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $errors = [],
        public readonly array $meta = []
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function ok(array $meta = []): self
    {
        return new self(true, [], $meta);
    }

    /**
     * @param array<int, TemplateValidationError> $errors
     * @param array<string, mixed> $meta
     */
    public static function fail(array $errors, array $meta = []): self
    {
        return new self(false, $errors, $meta);
    }
}

