<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\CustomField;

use Vendor\ImportKit\Contracts\CustomFieldCatalogInterface;
use Vendor\ImportKit\DTO\ImportRunContext;

final class NullCustomFieldCatalog implements CustomFieldCatalogInterface
{
    public function activeFields(string $kind, ImportRunContext $context): array
    {
        return [];
    }
}

