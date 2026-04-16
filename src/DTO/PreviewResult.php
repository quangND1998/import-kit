<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

final class PreviewResult
{
    /**
     * @param array<int, PreviewRowResult> $rows
     * @param array<string, int> $summary
     * @param array<string, mixed> $pagination
     * @param array<string, string> $columnLabels
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $kind,
        public readonly array $summary,
        public readonly array $pagination,
        public readonly array $rows,
        public readonly array $columnLabels = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $rows = array_map(static fn (PreviewRowResult $row) => $row->toArray(), $this->rows);
        $errorRows = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'error') {
                continue;
            }

            $errorRows[] = [
                'row' => (int) ($row['row'] ?? $row['line'] ?? 0),
                'errors' => (array) ($row['errors'] ?? []),
            ];
        }

        $page = (int) ($this->pagination['page'] ?? 1);
        $perPage = max(1, (int) ($this->pagination['per_page'] ?? 20));
        $totalRows = (int) ($this->summary['total_seen'] ?? 0);
        $lastPage = (int) ceil($totalRows / $perPage);

        return [
            'mode' => 'preview',
            'import_session_id' => $this->sessionId,
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_row_ok' => (int) ($this->summary['ok'] ?? 0),
            'total_row_error' => (int) ($this->summary['error'] ?? 0),
            'last_page' => max(1, $lastPage),
            'imported' => (int) ($this->summary['ok'] ?? 0),
            'skipped' => (int) ($this->summary['skipped_blank'] ?? 0),
            'rows' => $rows,
            'errors' => $errorRows,
            'column_labels' => $this->columnLabels,
            'session_id' => $this->sessionId,
            'kind' => $this->kind,
            'summary' => $this->summary,
            'pagination' => $this->pagination,
            'legacy_rows' => $rows,
        ];
    }
}
