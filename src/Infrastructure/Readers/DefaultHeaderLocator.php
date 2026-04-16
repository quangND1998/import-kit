<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Vendor\ImportKit\Contracts\HeaderLocatorInterface;
use Vendor\ImportKit\DTO\TemplateValidationResult;

final class DefaultHeaderLocator implements HeaderLocatorInterface
{
    private TemplateValidationResult $templateValidation;

    public function __construct()
    {
        $this->templateValidation = TemplateValidationResult::ok();
    }

    public function locate(Worksheet $sheet, int $highestRow, int $highestColumnIndex): array
    {
        $headerRow = 1;
        $headerMap = [];
        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; ++$columnIndex) {
            $raw = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $headerRow)->getValue();
            $key = $this->normalizeHeader($raw);
            if ($key !== '') {
                $headerMap[$key] = $columnIndex;
            }
        }

        return [
            'header_row' => $headerRow,
            'header_map' => $headerMap,
            'meta' => [],
            'template_validation' => $this->templateValidation,
        ];
    }

    public function templateValidation(): TemplateValidationResult
    {
        return $this->templateValidation;
    }

    private function normalizeHeader(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }

        $normalized = strtolower(trim((string) $raw));

        return str_replace([' ', '-'], '_', $normalized);
    }
}
