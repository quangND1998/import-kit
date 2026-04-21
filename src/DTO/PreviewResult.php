<?php

declare(strict_types=1);

namespace Vendor\ImportKit\DTO;

use Vendor\ImportKit\Services\ImportResponseFormatter;

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
        public readonly array $columnLabels = [],
        public readonly bool $validated = true,
        public readonly string $dataSource = 'file',
        public readonly array $filters = ['status' => 'all']
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $rows = array_map(static fn (PreviewRowResult $row) => $row->toArray(), $this->rows);
        $formatter = new ImportResponseFormatter();

        return $formatter->format(
            mode: 'preview',
            id: $this->sessionId,
            rows: $rows,
            pagination: [
                'page' => (int) ($this->pagination['page'] ?? 1),
                'per_page' => (int) ($this->pagination['per_page'] ?? 20),
                'filtered_total' => (int) ($this->summary['total_seen'] ?? count($rows)),
                'next_cursor' => $this->pagination['next_cursor'] ?? null,
            ],
            columnLabels: $this->columnLabels,
            meta: [
                'validated' => $this->validated,
                'source' => $this->dataSource,
                'skipped' => (int) ($this->summary['skipped_blank'] ?? 0),
            ],
            filters: $this->filters
        );
    }
}
