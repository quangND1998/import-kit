<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Exceptions;

use RuntimeException;
use Vendor\ImportKit\DTO\TemplateValidationError;

final class InvalidTemplateException extends RuntimeException
{
    /**
     * @param array<int, TemplateValidationError> $errors
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'Import template is invalid.'
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errorsToArray(): array
    {
        return array_map(static fn (TemplateValidationError $error): array => $error->toArray(), $this->errors);
    }
}

