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
        return [
            'session_id' => $this->sessionId,
            'kind' => $this->kind,
            'summary' => $this->summary,
            'pagination' => $this->pagination,
            'column_labels' => $this->columnLabels,
            'rows' => array_map(static fn (PreviewRowResult $row) => $row->toArray(), $this->rows),
        ];
    }
}
