<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\Models\ImportPreviewSession;
use Vendor\ImportKit\Models\ImportPreviewSnapshotRow;

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

    public function savePreviewSnapshot(string $id, array $rows, array $columnLabels = []): void
    {
        ImportPreviewSnapshotRow::query()->where('session_id', $id)->delete();

        if ($rows !== []) {
            $now = now();
            ImportPreviewSnapshotRow::query()->insert(array_map(
                static fn (array $row): array => [
                    'session_id' => $id,
                    'line' => (int) ($row['line'] ?? 0),
                    'status' => (string) ($row['status'] ?? 'unknown'),
                    'payload' => $row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows
            ));
        }

        ImportPreviewSession::query()->whereKey($id)->update([
            'context' => [
                'preview_snapshot' => [
                    'stored_rows' => count($rows),
                    'column_labels' => $columnLabels,
                ],
            ],
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
            ->pluck('payload')
            ->all();

        return [
            'rows' => $storedRows !== [] ? $storedRows : (array) ($snapshot['rows'] ?? []),
            'column_labels' => (array) ($snapshot['column_labels'] ?? []),
        ];
    }
}
