<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use Illuminate\Support\Facades\Config;
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

    private ?string $temporaryLocalPath = null;

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

        $this->cleanupTemporaryFile();
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
        $this->cleanupTemporaryFile();
    }

    private function resolveAbsolutePath(StoredFile $file): string
    {
        $metaPath = $file->meta['absolute_path'] ?? null;
        if (is_string($metaPath) && $metaPath !== '' && is_file($metaPath)) {
            return $metaPath;
        }

        $stream = Storage::disk($file->disk)->readStream($file->path);
        if ($stream === false) {
            throw new RuntimeException('Cannot open source spreadsheet: ' . $file->path . ' on disk ' . $file->disk);
        }

        $extension = strtolower((string) pathinfo($file->path, PATHINFO_EXTENSION));
        $temporaryDir = rtrim((string) Config::get('import.worker.local_temp_dir', sys_get_temp_dir()), DIRECTORY_SEPARATOR);
        if ($temporaryDir === '' || (!is_dir($temporaryDir) && !mkdir($temporaryDir, 0777, true) && !is_dir($temporaryDir))) {
            throw new RuntimeException('Unable to prepare local temp directory for import worker.');
        }

        $temporaryPath = tempnam($temporaryDir, 'import-kit-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create temporary file for spreadsheet import.');
        }

        if ($extension !== '') {
            $renamedPath = $temporaryPath . '.' . $extension;
            if (@rename($temporaryPath, $renamedPath)) {
                $temporaryPath = $renamedPath;
            }
        }

        $target = fopen($temporaryPath, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException('Unable to open local temporary file for writing.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
            fclose($stream);
        }

        if (!is_file($temporaryPath)) {
            throw new RuntimeException('Temporary spreadsheet file was not created.');
        }

        $this->temporaryLocalPath = $temporaryPath;

        return $temporaryPath;
    }

    private function cleanupTemporaryFile(): void
    {
        if ($this->temporaryLocalPath === null) {
            return;
        }

        if (is_file($this->temporaryLocalPath)) {
            @unlink($this->temporaryLocalPath);
        }

        $this->temporaryLocalPath = null;
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
