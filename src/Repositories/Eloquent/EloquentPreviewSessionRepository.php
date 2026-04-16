<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use JsonException;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\Models\ImportPreviewSession;
use Vendor\ImportKit\Models\ImportPreviewSnapshotRow;
use Vendor\ImportKit\Support\RowWindow;

final class EloquentPreviewSessionRepository implements PreviewSessionStoreInterface
{
    public function create(PreviewSessionData $session): PreviewSessionData
    {
        ImportPreviewSession::query()->create([
            'id' => $session->id,
            'kind' => $session->kind,
            'file_handle' => $session->fileHandle,
            'tenant_id' => $session->tenantId,
            'workspace_id' => $session->workspaceId,
            'context' => $session->context,
            'status' => $session->status,
            'expires_at' => $session->expiresAt,
        ]);

        return $session;
    }

    public function find(string $id): ?PreviewSessionData
    {
        $record = ImportPreviewSession::query()->find($id);
        if (!$record instanceof ImportPreviewSession) {
            return null;
        }

        return new PreviewSessionData(
            id: (string) $record->id,
            kind: (string) $record->kind,
            fileHandle: (string) $record->file_handle,
            tenantId: $record->tenant_id !== null ? (int) $record->tenant_id : null,
            workspaceId: $record->workspace_id !== null ? (int) $record->workspace_id : null,
            context: (array) ($record->context ?? []),
            status: (string) $record->status,
            expiresAt: CarbonImmutable::parse($record->expires_at)
        );
    }

    public function updateStatus(string $id, string $status): void
    {
        ImportPreviewSession::query()->whereKey($id)->update([
            'status' => $status,
        ]);
    }

    public function savePreviewSnapshot(string $id, array $rows, array $columnLabels = [], array $meta = []): void
    {
        ImportPreviewSnapshotRow::query()->where('session_id', $id)->delete();

        if ($rows !== []) {
            $now = CarbonImmutable::now();
            ImportPreviewSnapshotRow::query()->insert(array_map(
                fn (array $row): array => [
                    'session_id' => $id,
                    'line' => (int) ($row['line'] ?? $row['row'] ?? 0),
                    'status' => (string) ($row['status'] ?? 'unknown'),
                    'payload' => $this->encodeJson($row),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows
            ));
        }

        ImportPreviewSession::query()->whereKey($id)->update([
            'context' => $this->encodeJson([
                'preview_snapshot' => [
                    'stored_rows' => count($rows),
                    'column_labels' => $columnLabels,
                    'meta' => $meta,
                ],
            ]),
        ]);
    }

    public function getPreviewSnapshot(string $id): ?array
    {
        $record = ImportPreviewSession::query()->find($id);
        if (!$record instanceof ImportPreviewSession) {
            return null;
        }

        $snapshot = (array) (($record->context ?? [])['preview_snapshot'] ?? []);
        if ($snapshot === []) {
            return null;
        }

        $storedRows = ImportPreviewSnapshotRow::query()
            ->where('session_id', $id)
            ->orderBy('line')
            ->get()
            ->map(static fn (ImportPreviewSnapshotRow $item): array => (array) $item->payload)
            ->all();

        return [
            'rows' => $storedRows !== [] ? $storedRows : (array) ($snapshot['rows'] ?? []),
            'column_labels' => (array) ($snapshot['column_labels'] ?? []),
            'meta' => (array) ($snapshot['meta'] ?? []),
        ];
    }

    public function getPreviewSnapshotRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): ?array
    {
        $snapshot = $this->getPreviewSnapshot($id);
        if (!is_array($snapshot)) {
            return null;
        }

        $window = $rowWindow ?? new RowWindow(0, (int) config('import.preview.default_per_page', 20));

        $query = ImportPreviewSnapshotRow::query()->where('session_id', $id);
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $filteredTotal = (int) $query->count();
        $records = $query
            ->orderBy('line')
            ->offset($window->offset)
            ->limit($window->limit)
            ->get();

        $rows = $records
            ->map(static fn (ImportPreviewSnapshotRow $item): array => (array) ($item->payload ?? []))
            ->all();

        $nextCursor = ($window->offset + count($rows)) < $filteredTotal
            ? (string) ($window->offset + $window->limit)
            : null;

        return [
            'rows' => $rows,
            'column_labels' => (array) ($snapshot['column_labels'] ?? []),
            'meta' => (array) ($snapshot['meta'] ?? []),
            'pagination' => [
                'page' => $window->page(),
                'per_page' => $window->limit,
                'filtered_total' => $filteredTotal,
                'next_cursor' => $nextCursor,
            ],
            'filters' => [
                'status' => $status ?? 'all',
            ],
        ];
    }

    /**
     * @param array<mixed> $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return (string) json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[]';
        }
    }
}
