<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\CustomFieldDefinition;
use Vendor\ImportKit\DTO\ImportRunContext;

interface CustomFieldCatalogAwareImportModuleInterface
{
    /**
     * @return array<int, CustomFieldDefinition>
     */
    public function activeCustomFields(ImportRunContext $context): array;
}

