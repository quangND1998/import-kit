<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Modules\Concerns;

use Vendor\ImportKit\DTO\ImportRunContext;

trait HasImportRunContext
{
    protected ImportRunContext $importRunContext;

    public function setImportRunContext(ImportRunContext $importRunContext): void
    {
        $this->importRunContext = $importRunContext;
    }
}