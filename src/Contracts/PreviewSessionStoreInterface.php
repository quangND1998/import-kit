<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\PreviewSessionData;

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
}
