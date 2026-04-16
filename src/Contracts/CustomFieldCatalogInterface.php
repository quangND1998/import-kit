<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\CustomFieldDefinition;
use Vendor\ImportKit\DTO\ImportRunContext;

interface CustomFieldCatalogInterface
{
    /**
     * @return array<int, CustomFieldDefinition>
     */
    public function activeFields(string $kind, ImportRunContext $context): array;
}

