<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Vendor\ImportKit\Contracts\CustomFieldCatalogInterface;
use Vendor\ImportKit\Contracts\HeaderLocatorInterface;
use Vendor\ImportKit\Contracts\HeaderPolicyResolverInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\TemplateValidationError;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\DTO\CustomFieldDefinition;

final class ConfigurableHeaderLocator implements HeaderLocatorInterface
{
    private TemplateValidationResult $templateValidation;

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    public function __construct(
        private readonly HeaderPolicyResolverInterface $policyResolver,
        private readonly CustomFieldCatalogInterface $customFieldCatalog,
        private readonly ?string $kind = null,
        private readonly ?ImportRunContext $context = null,
        private readonly ?HeaderPolicy $policyOverride = null,
        private readonly array $customFieldsOverride = []
    ) {
        $this->templateValidation = TemplateValidationResult::ok();
    }

    public function locate(Worksheet $sheet, int $highestRow, int $highestColumnIndex): array
    {
        $policy = $this->policyOverride ?? $this->policyResolver->resolve($this->kind);
        $headerRow = max(1, min($policy->headerRowIndex, max(1, $highestRow)));
        $headerMap = [];
        $errors = [];
        $customFieldMap = [];
        $activeCustomFields = $this->activeCustomFieldMap();

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; ++$columnIndex) {
            $raw = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $headerRow)->getValue();
            $label = trim((string) ($raw ?? ''));
            if ($label === '') {
                continue;
            }

            $normalized = $this->normalizeHeader($label, $policy->normalizeMode);
            $headerMap[$normalized] = $columnIndex;

            if ($policy->customFieldStartColumn !== null && $columnIndex >= $policy->customFieldStartColumn) {
                $customFieldId = $this->extractCustomFieldId($label, $policy->customFieldPattern);
                if ($customFieldId === null) {
                    $errors[] = new TemplateValidationError(
                        code: 'invalid_custom_header_format',
                        message: "Custom field header '{$label}' has invalid format.",
                        field: $normalized,
                        meta: ['column_index' => $columnIndex]
                    );
                    continue;
                }

                if ($activeCustomFields !== [] && !isset($activeCustomFields[$customFieldId])) {
                    $errors[] = new TemplateValidationError(
                        code: 'custom_field_not_active',
                        message: "Custom field '{$customFieldId}' is not active.",
                        field: $normalized,
                        meta: ['column_index' => $columnIndex]
                    );
                    continue;
                }

                $customFieldMap[$normalized] = [
                    'custom_field_id' => $customFieldId,
                    'column_index' => $columnIndex,
                    'label' => $label,
                    'data_type' => $activeCustomFields[$customFieldId]['data_type'] ?? null,
                ];
            }
        }

        foreach ($policy->requiredHeaders as $required) {
            if (!array_key_exists($required, $headerMap)) {
                $errors[] = new TemplateValidationError(
                    code: 'missing_required_header',
                    message: "Missing required header '{$required}'.",
                    field: $required
                );
            }
        }

        if ($policy->strictOrder) {
            foreach ($policy->strictCoreColumns as $expectedIndex => $expectedLabel) {
                $columnIndex = (int) $expectedIndex;
                $raw = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $headerRow)->getValue();
                $actualLabel = trim((string) ($raw ?? ''));
                if ($actualLabel !== $expectedLabel) {
                    $errors[] = new TemplateValidationError(
                        code: 'invalid_header_position',
                        message: "Column {$columnIndex} must be '{$expectedLabel}' (actual '{$actualLabel}').",
                        field: $this->normalizeHeader($expectedLabel, $policy->normalizeMode),
                        meta: [
                            'column_index' => $columnIndex,
                            'expected' => $expectedLabel,
                            'actual' => $actualLabel,
                        ]
                    );
                }
            }
        }

        $this->metadata = [
            'kind' => $this->kind,
            'header_row' => $headerRow,
            'custom_field_map' => $customFieldMap,
            'policy' => [
                'strict_order' => $policy->strictOrder,
                'custom_field_start_column' => $policy->customFieldStartColumn,
            ],
        ];
        $this->templateValidation = $errors === []
            ? TemplateValidationResult::ok($this->metadata)
            : TemplateValidationResult::fail($errors, $this->metadata);

        return [
            'header_row' => $headerRow,
            'header_map' => $headerMap,
            'meta' => $this->metadata,
            'template_validation' => $this->templateValidation,
        ];
    }

    public function templateValidation(): TemplateValidationResult
    {
        return $this->templateValidation;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    private function normalizeHeader(string $label, string $mode): string
    {
        $normalized = strtolower(trim($label));
        if ($mode === 'raw') {
            return $normalized;
        }

        return str_replace([' ', '-'], '_', $normalized);
    }

    private function extractCustomFieldId(string $label, string $pattern): ?string
    {
        if (@preg_match($pattern, $label, $matches) !== 1) {
            return null;
        }

        $id = (string) ($matches['id'] ?? '');
        return $id !== '' ? $id : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function activeCustomFieldMap(): array
    {
        $fields = $this->customFieldsOverride;
        if ($fields === []) {
            $context = $this->context ?? new ImportRunContext(null, null, []);
            $fields = $this->customFieldCatalog->activeFields((string) $this->kind, $context);
        }
        $map = [];

        foreach ($fields as $field) {
            if (!$field instanceof CustomFieldDefinition) {
                continue;
            }
            $map[$field->id] = [
                'id' => $field->id,
                'title' => $field->title,
                'data_type' => $field->dataType,
            ];
        }

        return $map;
    }
}

