<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Modules\Samples;

use Vendor\ImportKit\Contracts\HeaderPolicyAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\ImportModuleInterface;
use Vendor\ImportKit\Contracts\RowCommitterInterface;
use Vendor\ImportKit\Contracts\RowMapperInterface;
use Vendor\ImportKit\Contracts\RowParserInterface;
use Vendor\ImportKit\Contracts\RowValidatorInterface;
use Vendor\ImportKit\Contracts\TemplateErrorMessageAwareImportModuleInterface;
use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationError;
use Vendor\ImportKit\DTO\ValidationResult;
use Vendor\ImportKit\Modules\Concerns\HasHeaderPolicy;
use Vendor\ImportKit\DTO\CustomFieldDefinition;
use Vendor\ImportKit\Support\HeaderLabelNormalization;

/**
 * Matches the sample employee spreadsheet: header row 6, columns A–E.
 * For local/smoke testing only; copy or extend in the host application.
 */
final class EmployeeImportTestModule implements
    ImportModuleInterface,
    HeaderPolicyAwareImportModuleInterface,
    TemplateErrorMessageAwareImportModuleInterface
{
    use HasHeaderPolicy;

    public const KIND = 'employee_import_test';
    private array $customFields;
    public function __construct()
    {
        $this->customFields = [
            new CustomFieldDefinition(id: '123', title: 'Thông tin tùy chỉnh 1', dataType: 'NUMBER'),
            new CustomFieldDefinition(id: '124', title: 'Thông tin tùy chỉnh 2', dataType: 'DATE'),
        ];
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function requiredHeaders(): array
    {
        return [
            'ho_va_ten*',
            'ma_nhan_vien',
            'email_cong_ty',
        ];
    }

    public function optionalHeaders(): array
    {
        return [];
    }

    public function columnLabels(): array
    {
        $customFields = $this->customFields;
        $columnLabels = [
            'full_name' => 'Họ và tên',
            'employee_code' => 'Mã nhân viên',
            'company_email' => 'Email công ty',
        ];
        foreach ($customFields as $customField) {
            if (!$customField instanceof CustomFieldDefinition) {
                continue;
            }

            $title = (string) ($customField->title ?? '');
            $key = HeaderLabelNormalization::normalize($title, 'snake_unaccent');
            if ($key === '') {
                continue;
            }

            $columnLabels[$key] = $title;
        }

        return $columnLabels;
    }

    public function invalidTemplateMessage(): string
    {
        return (string) \__('import_kit::employee.import_template_invalid');
    }

    public function headerPolicy(ImportRunContext $context): HeaderPolicy
    {
        return $this->makeHeaderPolicy(
            row: 6,
            strictOrder: true,
            strictCoreColumns: [
                1 => 'Họ và tên*',
                2 => 'Mã nhân viên',
                3 => 'Email công ty',
            ],
            requiredHeaders: [
                'ho_va_ten*',
                'ma_nhan_vien',
                'email_cong_ty',
            ],
            normalizeMode: 'snake_unaccent',
        );
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
                $email = $normalizedRow['email_cong_ty'] ?? null;
                $emailStr = is_string($email) ? trim($email) : '';
                if ($emailStr !== '' && filter_var($emailStr, FILTER_VALIDATE_EMAIL) === false) {
                    return ValidationResult::fail([
                        new ValidationError(
                            field: 'email_cong_ty',
                            code: 'invalid_email',
                            message: (string) \__('import_kit::employee.validation.invalid_company_email')
                        ),
                    ]);
                }

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
        return new class() implements RowCommitterInterface {
            public function commit(array $mappedRow, ImportRunContext $context): void
            {
            }
        };
    }
}
