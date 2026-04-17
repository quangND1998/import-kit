<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\Support\RowWindow;

final class CsvSourceReader implements SourceReaderInterface
{
    /**
     * @var resource|null
     */
    private $handle = null;

    /**
     * @var array<int, string>
     */
    private array $headers = [];

    private ?string $temporaryLocalPath = null;

    public function open(StoredFile $file): void
    {
        $this->cleanupTemporaryFile();
        $localPath = $this->stageToLocalTemp($file);
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open staged source file: ' . $localPath);
        }

        $this->handle = $stream;
        $headerRow = fgetcsv($this->handle);
        $this->headers = array_map(static fn ($value): string => trim((string) $value), $headerRow ?: []);
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function metadata(): array
    {
        return [];
    }

    public function templateValidation(): TemplateValidationResult
    {
        return TemplateValidationResult::ok();
    }

    public function rows(?RowWindow $window = null): iterable
    {
        if (!is_resource($this->handle)) {
            throw new RuntimeException('Reader is not opened.');
        }

        $rowIndex = 0;
        $offset = $window?->offset ?? 0;
        $limit = $window?->limit ?? PHP_INT_MAX;

        while (($row = fgetcsv($this->handle)) !== false) {
            if ($rowIndex < $offset) {
                $rowIndex++;
                continue;
            }

            if (($rowIndex - $offset) >= $limit) {
                break;
            }

            $rowIndex++;
            yield $this->associate($row);
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }

        $this->cleanupTemporaryFile();
    }

    /**
     * @param array<int, mixed> $row
     * @return array<string, mixed>
     */
    private function associate(array $row): array
    {
        $normalized = [];
        foreach ($this->headers as $index => $header) {
            $normalized[$header] = $row[$index] ?? null;
        }

        return $normalized;
    }

    private function stageToLocalTemp(StoredFile $file): string
    {
        $stream = Storage::disk($file->disk)->readStream($file->path);
        if ($stream === false) {
            throw new RuntimeException('Cannot open source file: ' . $file->path . ' on disk ' . $file->disk);
        }

        $extension = strtolower((string) pathinfo($file->path, PATHINFO_EXTENSION));
        $temporaryDir = rtrim((string) Config::get('import.worker.local_temp_dir', sys_get_temp_dir()), DIRECTORY_SEPARATOR);
        if ($temporaryDir === '' || (!is_dir($temporaryDir) && !mkdir($temporaryDir, 0777, true) && !is_dir($temporaryDir))) {
            fclose($stream);
            throw new RuntimeException('Unable to prepare local temp directory for CSV import worker.');
        }

        $temporaryPath = tempnam($temporaryDir, 'import-kit-');
        if ($temporaryPath === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create temporary file for CSV import.');
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
            throw new RuntimeException('Unable to open local temporary CSV file for writing.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
            fclose($stream);
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
}
