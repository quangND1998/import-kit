<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

use Carbon\CarbonImmutable;

final class ImportJobData
{
    /**
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $sessionId,
        public readonly string $status,
        public readonly ?int $submittedBy,
        public readonly ?int $tenantId,
        public readonly ?int $workspaceId,
        public readonly int $totalRows = 0,
        public readonly int $processedRows = 0,
        public readonly int $okRows = 0,
        public readonly int $errorRows = 0,
        public readonly int $skippedBlankRows = 0,
        public readonly array $summary = [],
        public readonly ?CarbonImmutable $startedAt = null,
        public readonly ?CarbonImmutable $finishedAt = null
    ) {
    }
}
