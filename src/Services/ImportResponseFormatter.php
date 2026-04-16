<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

final class ImportResponseFormatter
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $pagination
     * @param array<string, string> $columnLabels
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function format(
        string $mode,
        string $id,
        array $rows,
        array $pagination,
        array $columnLabels = [],
        array $meta = [],
        array $filters = ['status' => 'all']
    ): array {
        $perPage = max(1, (int) ($pagination['per_page'] ?? 20));
        $totalRows = (int) ($pagination['filtered_total'] ?? count($rows));
        $lastPage = (int) ceil($totalRows / $perPage);

        $okCount = 0;
        $errorCount = 0;
        $errorRows = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'unknown');
            if ($status === 'ok') {
                $okCount++;
            } elseif ($status === 'error') {
                $errorCount++;
                $errorRows[] = [
                    'row' => (int) ($row['row'] ?? $row['line'] ?? 0),
                    'errors' => (array) ($row['errors'] ?? []),
                ];
            }
        }

        $idKey = $mode === 'result' ? 'import_job_id' : 'import_session_id';
        $sourceDefault = $mode === 'result' ? 'job_result' : 'session';
        $validatedDefault = $mode === 'result';

        return [
            'mode' => $mode,
            $idKey => $id,
            'validated' => (bool) ($meta['validated'] ?? $validatedDefault),
            'data_source' => (string) ($meta['source'] ?? $sourceDefault),
            'page' => (int) ($pagination['page'] ?? 1),
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_row_ok' => $okCount,
            'total_row_error' => $errorCount,
            'last_page' => max(1, $lastPage),
            'imported' => $okCount,
            'skipped' => (int) ($meta['skipped'] ?? 0),
            'rows' => $rows,
            'errors' => $errorRows,
            'column_labels' => $columnLabels,
            'meta' => [
                'validated' => (bool) ($meta['validated'] ?? $validatedDefault),
                'source' => (string) ($meta['source'] ?? $sourceDefault),
            ],
            'pagination' => $pagination,
            'filters' => $filters,
        ];
    }
}

