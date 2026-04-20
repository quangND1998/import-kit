<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Fixtures;

use Vendor\ImportKit\Contracts\RowCommitterInterface;
use Vendor\ImportKit\DTO\ImportRunContext;

final class FakeContextAwareCommitter implements RowCommitterInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $committed = [];

    public function commit(array $mappedRow, ImportRunContext $context): void
    {
        $this->committed[] = [
            'row' => $mappedRow,
            'workspace_id' => $context->workspaceId,
            'tenant_id' => $context->tenantId,
        ];
    }
}
