<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Repositories\Mongo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\DTO\PreviewSessionData;

final class MongoPreviewSessionRepository implements PreviewSessionStoreInterface
{
    public function create(PreviewSessionData $session): PreviewSessionData
    {
        $this->query()->insert([
            '_id' => $session->id,
            'kind' => $session->kind,
            'file_handle' => $session->fileHandle,
            'tenant_id' => $session->tenantId,
            'workspace_id' => $session->workspaceId,
            'context' => $session->context,
            'status' => $session->status,
            'expires_at' => $session->expiresAt->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $session;
    }

    public function find(string $id): ?PreviewSessionData
    {
        $record = (array) $this->query()->where('_id', $id)->first();
        if ($record === []) {
            return null;
        }

        return new PreviewSessionData(
            id: (string) ($record['_id'] ?? $id),
            kind: (string) ($record['kind'] ?? ''),
            fileHandle: (string) ($record['file_handle'] ?? ''),
            tenantId: isset($record['tenant_id']) ? (int) $record['tenant_id'] : null,
            workspaceId: isset($record['workspace_id']) ? (int) $record['workspace_id'] : null,
            context: (array) ($record['context'] ?? []),
            status: (string) ($record['status'] ?? 'pending'),
            expiresAt: CarbonImmutable::parse((string) ($record['expires_at'] ?? now()->toDateTimeString()))
        );
    }

    public function updateStatus(string $id, string $status): void
    {
        $this->query()->where('_id', $id)->update([
            'status' => $status,
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function savePreviewSnapshot(string $id, array $rows, array $columnLabels = []): void
    {
        $this->snapshotRowsQuery()->where('session_id', $id)->delete();
        if ($rows !== []) {
            $now = now()->toDateTimeString();
            $payload = array_map(
                static fn (array $row): array => [
                    'session_id' => $id,
                    'line' => (int) ($row['line'] ?? 0),
                    'status' => (string) ($row['status'] ?? 'unknown'),
                    'payload' => $row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows
            );
            $this->snapshotRowsQuery()->insert($payload);
        }

        $this->query()->where('_id', $id)->update([
            'context.preview_snapshot' => [
                'stored_rows' => count($rows),
                'column_labels' => $columnLabels,
            ],
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function getPreviewSnapshot(string $id): ?array
    {
        $record = (array) $this->query()->where('_id', $id)->first();
        if ($record === []) {
            return null;
        }

        $snapshot = (array) (($record['context']['preview_snapshot'] ?? []));
        if ($snapshot === []) {
            return null;
        }

        $storedRows = array_map(
            static fn (array $item): array => (array) ($item['payload'] ?? []),
            (array) $this->snapshotRowsQuery()->where('session_id', $id)->orderBy('line')->get()->all()
        );

        return [
            'rows' => $storedRows !== [] ? $storedRows : (array) ($snapshot['rows'] ?? []),
            'column_labels' => (array) ($snapshot['column_labels'] ?? []),
        ];
    }

    private function query()
    {
        return DB::connection((string) config('import.database.mongo.connection', 'mongodb'))
            ->table((string) config('import.database.mongo.preview_sessions_collection', 'import_preview_sessions'));
    }

    private function snapshotRowsQuery()
    {
        return DB::connection((string) config('import.database.mongo.connection', 'mongodb'))
            ->table((string) config('import.database.mongo.preview_snapshot_rows_collection', 'import_preview_snapshot_rows'));
    }
}
