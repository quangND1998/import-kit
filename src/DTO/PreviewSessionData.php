<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

use Carbon\CarbonImmutable;

final class PreviewSessionData
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $fileHandle,
        public readonly ?int $tenantId,
        public readonly ?int $workspaceId,
        public readonly array $context,
        public readonly string $status,
        public readonly CarbonImmutable $expiresAt
    ) {
    }

    public function runContext(): ImportRunContext
    {
        return ImportRunContext::from(
            tenantId: $this->tenantId,
            workspaceId: $this->workspaceId,
            context: $this->context
        );
    }
}
