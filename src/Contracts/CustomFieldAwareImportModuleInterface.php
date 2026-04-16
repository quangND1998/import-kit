<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\CustomFieldValue;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationError;

interface CustomFieldAwareImportModuleInterface extends ImportModuleInterface
{
    /**
     * @param array<string, mixed> $normalizedRow
     * @param array<int, CustomFieldValue> $customFieldValues
     * @return array<int, ValidationError>
     */
    public function validateCustomFieldValues(array $normalizedRow, array $customFieldValues, ImportRunContext $context): array;
}

