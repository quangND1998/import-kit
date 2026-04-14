<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class ImportRunContext
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly ?int $tenantId,
        public readonly ?int $workspaceId,
        public readonly array $context = []
    ) {
    }
}
