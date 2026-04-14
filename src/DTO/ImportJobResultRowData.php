<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ImportJobResultRowData
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $jobId,
        public readonly int $line,
        public readonly string $status,
        public readonly array $payload = []
    ) {
    }
}
