<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\StoredFile;

interface SourceReaderResolverInterface
{
    public function resolve(
        StoredFile $file,
        ?string $kind = null,
        ?ImportModuleInterface $module = null,
        ?ImportRunContext $context = null
    ): SourceReaderInterface;
}
