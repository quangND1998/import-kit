<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Support\RowWindow;

final class ImportResultExportService
{
    public function __construct(
        private readonly ImportJobRepositoryInterface $jobs
    ) {
    }

    public function exportCsvByStatus(string $jobId, ?string $status = null): string
    {
        $resolvedStatus = $this->normalizeStatus($status);
        $cursor = 0;
        $pageSize = 500;
        $lines = [];
        $header = [];

        while (true) {
            $chunk = $this->jobs->getResultRows(
                $jobId,
                $resolvedStatus,
                new RowWindow($cursor, $pageSize)
            );

            $rows = (array) ($chunk['rows'] ?? []);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if ($header === []) {
                    $header = array_keys($row);
                    $lines[] = $this->toCsvLine($header);
                }

                $line = [];
                foreach ($header as $key) {
                    $value = $row[$key] ?? null;
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $line[] = $value;
                }
                $lines[] = $this->toCsvLine($line);
            }

            $nextCursor = $chunk['pagination']['next_cursor'] ?? null;
            if (!is_string($nextCursor) || $nextCursor === '') {
                break;
            }

            $cursor = (int) $nextCursor;
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim($status));
        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function toCsvLine(array $values): string
    {
        $escaped = array_map(static function ($value): string {
            $stringValue = (string) ($value ?? '');
            $stringValue = str_replace('"', '""', $stringValue);

            return '"' . $stringValue . '"';
        }, $values);

        return implode(',', $escaped);
    }
}
