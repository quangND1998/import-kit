<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\Support\RowWindow;

final class ImportResultService
{
    public function __construct(
        private readonly ImportJobRepositoryInterface $jobs,
        private readonly PreviewSessionStoreInterface $sessions
    ) {
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   column_labels: array<string, string>,
     *   pagination: array{page: int, per_page: int, filtered_total: int, next_cursor: ?string},
     *   filters: array{status: string}
     * }|null
     */
    public function previewRows(string $sessionId, ?string $status = null, ?RowWindow $rowWindow = null): ?array
    {
        return $this->sessions->getPreviewSnapshotRows($sessionId, $this->normalizeStatus($status), $rowWindow);
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page: int, per_page: int, filtered_total: int, next_cursor: ?string},
     *   filters: array{status: string}
     * }
     */
    public function resultRows(string $jobId, ?string $status = null, ?RowWindow $rowWindow = null): array
    {
        return $this->jobs->getResultRows($jobId, $this->normalizeStatus($status), $rowWindow);
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
}
