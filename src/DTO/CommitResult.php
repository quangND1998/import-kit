<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class CommitResult
{
    /**
     * @param array<string, int> $summary
     * @param array<int, ImportResultRowData> $rows
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $status,
        public readonly array $summary = [],
        public readonly array $rows = []
    ) {
    }
}
