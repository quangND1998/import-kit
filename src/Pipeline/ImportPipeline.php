<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Pipeline;

use RuntimeException;
use Vendor\ImportKit\Contracts\ImportModuleInterface;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\DTO\CommitResult;
use Vendor\ImportKit\DTO\ImportResultRowData;
use Vendor\ImportKit\DTO\PreviewResult;
use Vendor\ImportKit\DTO\PreviewRowResult;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\Support\ImportMode;
use Vendor\ImportKit\Support\RowWindow;

final class ImportPipeline
{
    /**
     * @return PreviewResult|CommitResult
     */
    public function run(
        string $mode,
        string $sessionId,
        ImportModuleInterface $module,
        StoredFile $file,
        SourceReaderInterface $reader,
        ?RowWindow $rowWindow = null
    ) {
        $reader->open($file);
        $headers = $reader->headers();

        $missingHeaders = array_diff($module->requiredHeaders(), $headers);
        if ($missingHeaders !== []) {
            throw new RuntimeException('Missing required headers: ' . implode(', ', $missingHeaders));
        }

        $parser = $module->makeRowParser();
        $validator = $module->makeRowValidator();
        $mapper = $module->makeRowMapper();
        $committer = $module->makeRowCommitter();

        $line = 1;
        $summary = [
            'total_seen' => 0,
            'ok' => 0,
            'error' => 0,
            'skipped_blank' => 0,
        ];
        $rows = [];

        foreach ($reader->rows($rowWindow) as $row) {
            $line++;
            $summary['total_seen']++;

            $normalized = $parser->parse($row);
            if ($this->isBlankRow($normalized)) {
                $summary['skipped_blank']++;
                continue;
            }

            $validation = $validator->validate($normalized);
            if (!$validation->ok) {
                $summary['error']++;
                if ($mode === ImportMode::PREVIEW) {
                    $rows[] = new PreviewRowResult($line, 'error', $validation->errors, $normalized);
                } elseif ($mode === ImportMode::COMMIT) {
                    $rows[] = new ImportResultRowData($line, 'error', $validation->errors, $normalized);
                }
                continue;
            }

            $mapped = $mapper->map($normalized);
            $summary['ok']++;

            if ($mode === ImportMode::COMMIT) {
                $committer->commit($mapped);
                $rows[] = new ImportResultRowData($line, 'ok', [], $normalized, $mapped);
                continue;
            }

            $rows[] = new PreviewRowResult($line, 'ok', [], $normalized, $mapped);
        }

        $reader->close();

        if ($mode === ImportMode::COMMIT) {
            return new CommitResult($sessionId, 'completed', $summary, $rows);
        }

        $window = $rowWindow ?? new RowWindow(0, (int) config('import.preview.default_per_page', 20));
        $page = $window->page();
        $perPage = $window->limit;
        $filteredTotal = count($rows);
        return new PreviewResult(
            sessionId: $sessionId,
            kind: $module->kind(),
            summary: $summary,
            pagination: [
                'page' => $page,
                'per_page' => $perPage,
                'filtered_total' => $filteredTotal,
                'next_cursor' => $filteredTotal === $perPage ? (string) ($window->offset + $perPage) : null,
            ],
            rows: $rows,
            columnLabels: $module->columnLabels()
        );
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function isBlankRow(array $normalized): bool
    {
        foreach ($normalized as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
