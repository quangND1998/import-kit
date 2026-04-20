<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Fixtures;

use Vendor\ImportKit\Contracts\CustomFieldAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\RowCommitterInterface;
use Vendor\ImportKit\Contracts\RowMapperInterface;
use Vendor\ImportKit\Contracts\RowParserInterface;
use Vendor\ImportKit\Contracts\RowValidatorInterface;
use Vendor\ImportKit\DTO\CustomFieldValue;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationError;
use Vendor\ImportKit\DTO\ValidationResult;

final class FakeImportModule implements CustomFieldAwareImportModuleInterface
{
    public function __construct(
        private readonly array $requiredHeaders = [],
        private readonly ?RowCommitterInterface $committer = null
    ) {
    }

    public function kind(): string
    {
        return 'employee_update';
    }

    public function requiredHeaders(): array
    {
        return $this->requiredHeaders;
    }

    public function optionalHeaders(): array
    {
        return [];
    }

    public function columnLabels(): array
    {
        return [];
    }

    public function makeRowParser(): RowParserInterface
    {
        return new class() implements RowParserInterface {
            public function parse(array $row, ImportRunContext $context): array
            {
                return $row;
            }
        };
    }

    public function makeRowValidator(): RowValidatorInterface
    {
        return new class() implements RowValidatorInterface {
            public function validate(array $normalizedRow, ImportRunContext $context): ValidationResult
            {
                return ValidationResult::ok();
            }
        };
    }

    public function makeRowMapper(): RowMapperInterface
    {
        return new class() implements RowMapperInterface {
            public function map(array $validatedRow, ImportRunContext $context): array
            {
                return $validatedRow;
            }
        };
    }

    public function makeRowCommitter(): RowCommitterInterface
    {
        return $this->committer ?? new class() implements RowCommitterInterface {
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
        };
    }

    public function validateCustomFieldValues(array $normalizedRow, array $customFieldValues, ImportRunContext $context): array
    {
        $errors = [];

        foreach ($customFieldValues as $value) {
            if (!$value instanceof CustomFieldValue) {
                continue;
            }

            $dataType = (string) ($value->meta['data_type'] ?? '');
            if ($dataType === 'NUMBER' && $value->value !== null && $value->value !== '' && !is_numeric((string) $value->value)) {
                $errors[] = new ValidationError(
                    field: (string) $value->columnKey,
                    code: 'invalid_custom_field_number',
                    message: "Custom field {$value->customFieldId} expects numeric value."
                );
            }
        }

        return $errors;
    }
}
