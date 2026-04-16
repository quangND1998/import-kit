<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\DTO\ImportRunContext;

interface HeaderPolicyAwareImportModuleInterface
{
    public function headerPolicy(ImportRunContext $context): HeaderPolicy;
}

