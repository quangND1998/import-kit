<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\Support\RowWindow;

interface PreviewSessionStoreInterface
{
    public function create(PreviewSessionData $session): PreviewSessionData;

    public function find(string $id): ?PreviewSessionData;

    public function updateStatus(string $id, string $status): void;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $columnLabels
     */
    public function savePreviewSnapshot(string $id, array $rows, array $columnLabels = []): void;

    /**
     * @return array{rows: array<int, array<string, mixed>>, column_labels: array<string, string>}|null
     */
    public function getPreviewSnapshot(string $id): ?array;

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   column_labels: array<string, string>,
     *   pagination: array{page: int, per_page: int, filtered_total: int, next_cursor: ?string},
     *   filters: array{status: ?string}
     * }|null
     */
    public function getPreviewSnapshotRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): ?array;
}
