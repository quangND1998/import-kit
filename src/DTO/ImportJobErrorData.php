<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ImportJobErrorData
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $jobId,
        public readonly ?int $line,
        public readonly ?string $field,
        public readonly string $code,
        public readonly string $message,
        public readonly array $payload = []
    ) {
    }
}
