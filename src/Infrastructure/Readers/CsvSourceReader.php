<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

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

    public function open(StoredFile $file): void
    {
        $stream = Storage::disk($file->disk)->readStream($file->path);
        if ($stream === false) {
            throw new RuntimeException('Cannot open source file: ' . $file->path . ' on disk ' . $file->disk);
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
}
