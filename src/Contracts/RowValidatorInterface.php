<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationResult;

interface RowValidatorInterface
{
    /**
     * @param array<string, mixed> $normalizedRow
     */
    public function validate(array $normalizedRow, ImportRunContext $context): ValidationResult;
}
