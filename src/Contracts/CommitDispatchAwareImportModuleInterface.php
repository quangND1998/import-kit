<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;

interface CommitDispatchAwareImportModuleInterface
{
    /**
     * Optional per-module override for commit dispatch strategy.
     *
     * @return array{
     *   dispatch_mode?: 'single'|'bus_batch',
     *   batch?: array{
     *     chunk_size?: int,
     *     allow_failures?: bool
     *   }
     * }
     */
    public function commitDispatchOptions(ImportRunContext $context): array;
}

