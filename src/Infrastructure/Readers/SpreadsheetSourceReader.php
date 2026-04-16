<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Vendor\ImportKit\Contracts\HeaderLocatorInterface;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\Support\RowWindow;

final class SpreadsheetSourceReader implements SourceReaderInterface
{
    /**
     * @var \PhpOffice\PhpSpreadsheet\Spreadsheet|null
     */
    private $spreadsheet = null;

    private ?Worksheet $sheet = null;

    /**
     * @var array<string, int>
     */
    private array $headerMap = [];

    private int $headerRow = 1;

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    private TemplateValidationResult $templateValidation;

    public function __construct(
        private readonly HeaderLocatorInterface $headerLocator
    )
    {
        $this->templateValidation = TemplateValidationResult::ok();
    }

    public function open(StoredFile $file): void
    {
        if (!class_exists(IOFactory::class)) {
            throw new RuntimeException('PhpSpreadsheet is required for xlsx/xls import.');
        }

        $absolutePath = $this->resolveAbsolutePath($file);
        $this->spreadsheet = IOFactory::load($absolutePath);
        $this->sheet = $this->spreadsheet->getActiveSheet();

        $highestRow = (int) $this->sheet->getHighestRow();
        $highestColumn = $this->sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $located = $this->headerLocator->locate($this->sheet, $highestRow, $highestColumnIndex);
        $this->headerRow = max(1, (int) ($located['header_row'] ?? 1));
        $this->headerMap = (array) ($located['header_map'] ?? []);
        $this->metadata = (array) ($located['meta'] ?? []);
        $templateValidation = $located['template_validation'] ?? null;
        $this->templateValidation = $templateValidation instanceof TemplateValidationResult
            ? $templateValidation
            : TemplateValidationResult::ok($this->metadata);
    }

    public function headers(): array
    {
        return array_keys($this->headerMap);
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function templateValidation(): TemplateValidationResult
    {
        return $this->templateValidation;
    }

    public function rows(?RowWindow $window = null): iterable
    {
        if (!$this->sheet instanceof Worksheet) {
            throw new RuntimeException('Reader is not opened.');
        }

        $highestRow = (int) $this->sheet->getHighestRow();
        $offset = $window?->offset ?? 0;
        $limit = $window?->limit ?? PHP_INT_MAX;

        $seen = 0;
        $yielded = 0;

        for ($spreadsheetRow = $this->headerRow + 1; $spreadsheetRow <= $highestRow; ++$spreadsheetRow) {
            $row = $this->readRow($this->sheet, $spreadsheetRow, $this->headerMap);
            if ($this->isBlankRow($row)) {
                continue;
            }

            if ($seen < $offset) {
                $seen++;
                continue;
            }

            if ($yielded >= $limit) {
                break;
            }

            $seen++;
            $yielded++;
            yield $row;
        }
    }

    public function close(): void
    {
        if ($this->spreadsheet !== null) {
            $this->spreadsheet->disconnectWorksheets();
            $this->spreadsheet = null;
        }

        $this->sheet = null;
        $this->headerMap = [];
        $this->headerRow = 1;
        $this->metadata = [];
        $this->templateValidation = TemplateValidationResult::ok();
    }

    private function resolveAbsolutePath(StoredFile $file): string
    {
        $metaPath = $file->meta['absolute_path'] ?? null;
        if (is_string($metaPath) && $metaPath !== '' && is_file($metaPath)) {
            return $metaPath;
        }

        $path = Storage::disk($file->disk)->path($file->path);
        if (!is_file($path)) {
            throw new RuntimeException('Spreadsheet file not found at path: ' . $path);
        }

        return $path;
    }

    /**
     * @param array<string, int> $headerMap
     * @return array<string, mixed>
     */
    private function readRow(Worksheet $sheet, int $spreadsheetRow, array $headerMap): array
    {
        $cells = [];
        foreach ($headerMap as $name => $columnIndex) {
            $cells[$name] = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $spreadsheetRow)->getValue();
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

}
