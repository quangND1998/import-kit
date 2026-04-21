<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Vendor\ImportKit\Contracts\HeaderLocatorInterface;
use Vendor\ImportKit\Contracts\HeaderPolicyResolverInterface;
use Vendor\ImportKit\DTO\TemplateValidationError;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\Support\HeaderLabelNormalization;

final class ConfigurableHeaderLocator implements HeaderLocatorInterface
{
    private TemplateValidationResult $templateValidation;

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    public function __construct(
        private readonly HeaderPolicyResolverInterface $policyResolver,
        private readonly ?string $kind = null,
        private readonly ?HeaderPolicy $policyOverride = null,
    ) {
        $this->templateValidation = TemplateValidationResult::ok();
    }

    public function locate(Worksheet $sheet, int $highestRow, int $highestColumnIndex): array
    {
        $policy = $this->policyOverride ?? $this->policyResolver->resolve($this->kind);
        $headerRow = max(1, min($policy->headerRowIndex, max(1, $highestRow)));
        $headerMap = [];
        $errors = [];
        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; ++$columnIndex) {
            $raw = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $headerRow)->getValue();
            $label = trim((string) ($raw ?? ''));
            if ($label === '') {
                continue;
            }

            $normalized = $this->normalizeHeader($label, $policy->normalizeMode);
            $headerMap[$normalized] = $columnIndex;
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

        $this->metadata = [
            'kind' => $this->kind,
            'header_row' => $headerRow,
            'policy' => [
                'strict_order' => $policy->strictOrder,
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
        return HeaderLabelNormalization::normalize($label, $mode);
    }
}
