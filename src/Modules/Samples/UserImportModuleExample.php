<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Modules\Samples;

use Vendor\ImportKit\Contracts\CommitDispatchAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\HeaderPolicyAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\TemplateErrorMessageAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\RowCommitterInterface;
use Vendor\ImportKit\Contracts\RowMapperInterface;
use Vendor\ImportKit\Contracts\RowParserInterface;
use Vendor\ImportKit\Contracts\RowValidatorInterface;
use Vendor\ImportKit\DTO\CustomFieldDefinition;
use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationResult;
use Vendor\ImportKit\Modules\Concerns\HasHeaderPolicy;

/**
 * Example only: copy this class into your app layer.
 */
final class UserImportModuleExample implements
    \Vendor\ImportKit\Contracts\ImportModuleInterface,
    HeaderPolicyAwareImportModuleInterface,
    CommitDispatchAwareImportModuleInterface,
    TemplateErrorMessageAwareImportModuleInterface
{
    use HasHeaderPolicy;

    public function kind(): string
    {
        return 'user_import';
    }

    public function requiredHeaders(): array
    {
        return ['employee_id', 'full_name'];
    }

    public function optionalHeaders(): array
    {
        return [];
    }

    public function columnLabels(): array
    {
        return [
            'employee_id' => 'Ma dinh danh nhan vien',
            'full_name' => 'Ho va ten',
        ];
    }

    public function invalidTemplateMessage(): string
    {
        return 'Template import User khong hop le.';
    }

    public function headerPolicy(ImportRunContext $context): HeaderPolicy
    {
        return $this->makeHeaderPolicy(
            row: 2,
            strictOrder: true,
            strictCoreColumns: [
                1 => 'Ma dinh danh nhan vien',
                2 => 'Ho va ten*',
            ],
            requiredHeaders: ['ma_dinh_danh_nhan_vien', 'ho_va_ten*'],
        );
    }

    public function commitDispatchOptions(ImportRunContext $context): array
    {
        $forceSingle = (bool) ($context->context['force_single_dispatch'] ?? false);
        if ($forceSingle) {
            return [
                'dispatch_mode' => 'single',
            ];
        }

        return [
            'dispatch_mode' => 'bus_batch',
            'batch' => [
                'chunk_size' => 300,
                'allow_failures' => false,
            ],
        ];
    }

    public function makeRowParser(): RowParserInterface
    {
        return new class() implements RowParserInterface {
            public function parse(array $row, ImportRunContext $context): array
            {
                $row['workspace_id'] = $context->workspaceId;
                $row['tenant_id'] = $context->tenantId;

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
                $validatedRow['_context'] = [
                    'workspace_id' => $context->workspaceId,
                    'tenant_id' => $context->tenantId,
                ];

                return $validatedRow;
            }
        };
    }

    public function makeRowCommitter(): RowCommitterInterface
    {
        return new class() implements RowCommitterInterface {
            public function commit(array $mappedRow, ImportRunContext $context): void
            {
            }
        };
    }
}
