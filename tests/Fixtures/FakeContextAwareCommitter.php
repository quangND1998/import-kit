<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Fixtures;

use Vendor\ImportKit\Contracts\ContextAwareRowCommitterInterface;
use Vendor\ImportKit\DTO\ImportRunContext;

final class FakeContextAwareCommitter implements ContextAwareRowCommitterInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $committed = [];

    public function commit(array $mappedRow): void
    {
        $this->committed[] = [
            'row' => $mappedRow,
            'workspace_id' => null,
            'tenant_id' => null,
        ];
    }

    public function commitWithContext(array $mappedRow, ImportRunContext $context): void
    {
        $this->committed[] = [
            'row' => $mappedRow,
            'workspace_id' => $context->workspaceId,
            'tenant_id' => $context->tenantId,
        ];
    }
}

