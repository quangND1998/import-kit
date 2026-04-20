<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Vendor\ImportKit\Contracts\HeaderLocatorInterface;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\Support\HeaderLabelNormalization;

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
            $key = HeaderLabelNormalization::normalize((string) ($raw ?? ''), 'snake');
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

}
