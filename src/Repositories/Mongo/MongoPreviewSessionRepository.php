<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Repositories\Mongo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\Support\RowWindow;

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
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
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
            expiresAt: CarbonImmutable::parse((string) ($record['expires_at'] ?? CarbonImmutable::now()->toDateTimeString()))
        );
    }

    public function updateStatus(string $id, string $status): void
    {
        $this->query()->where('_id', $id)->update([
            'status' => $status,
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    public function updateFileContextAndStatus(string $id, string $fileHandle, array $context, string $status): void
    {
        $this->query()->where('_id', $id)->update([
            'file_handle' => $fileHandle,
            'context' => $context,
            'status' => $status,
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    public function savePreviewSnapshot(string $id, array $rows, array $columnLabels = [], array $meta = []): void
    {
        $this->snapshotRowsQuery()->where('session_id', $id)->delete();
        if ($rows !== []) {
            $now = CarbonImmutable::now()->toDateTimeString();
            $payload = array_map(
                static fn (array $row): array => [
                    'session_id' => $id,
                    'line' => (int) ($row['line'] ?? $row['row'] ?? 0),
                    'status' => (string) ($row['status'] ?? 'unknown'),
                    'payload' => $row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows
            );
            $this->snapshotRowsQuery()->insert($payload);
        }

        $overallOkRows = 0;
        $overallErrorRows = 0;
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'unknown');
            if ($status === 'ok') {
                $overallOkRows++;
            } elseif ($status === 'error') {
                $overallErrorRows++;
            }
        }

        $snapshotMeta = array_merge($meta, [
            'overall_total_rows' => count($rows),
            'overall_ok_rows' => $overallOkRows,
            'overall_error_rows' => $overallErrorRows,
        ]);

        $this->query()->where('_id', $id)->update([
            'context.preview_snapshot' => [
                'stored_rows' => count($rows),
                'column_labels' => $columnLabels,
                'meta' => $snapshotMeta,
            ],
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
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
            'meta' => (array) ($snapshot['meta'] ?? []),
        ];
    }

    public function getPreviewSnapshotRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): ?array
    {
        $snapshot = $this->getPreviewSnapshot($id);
        if (!is_array($snapshot)) {
            return null;
        }

        $window = $rowWindow ?? new RowWindow(0, (int) Config::get('import.preview.default_per_page', 20));

        $query = $this->snapshotRowsQuery()->where('session_id', $id);
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $filteredTotal = (int) $query->count();
        $records = (array) $query
            ->orderBy('line')
            ->offset($window->offset)
            ->limit($window->limit)
            ->get()
            ->all();

        $rows = array_map(
            static fn (array $item): array => (array) ($item['payload'] ?? []),
            $records
        );

        $nextCursor = ($window->offset + count($rows)) < $filteredTotal
            ? (string) ($window->offset + $window->limit)
            : null;

        $overallTotal = (int) $this->snapshotRowsQuery()
            ->where('session_id', $id)
            ->count();
        $overallOk = (int) $this->snapshotRowsQuery()
            ->where('session_id', $id)
            ->where('status', 'ok')
            ->count();
        $overallError = (int) $this->snapshotRowsQuery()
            ->where('session_id', $id)
            ->where('status', 'error')
            ->count();
        $meta = array_merge((array) ($snapshot['meta'] ?? []), [
            'overall_total_rows' => $overallTotal,
            'overall_ok_rows' => $overallOk,
            'overall_error_rows' => $overallError,
        ]);

        return [
            'rows' => $rows,
            'column_labels' => (array) ($snapshot['column_labels'] ?? []),
            'meta' => $meta,
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

    public function deleteExpiredPreviewSessions(): int
    {
        $now = CarbonImmutable::now()->toDateTimeString();
        $ids = $this->query()->where('expires_at', '<', $now)->pluck('_id')->all();
        if ($ids === []) {
            return 0;
        }

        $this->snapshotRowsQuery()->whereIn('session_id', $ids)->delete();

        return (int) $this->query()->whereIn('_id', $ids)->delete();
    }

    private function query()
    {
        return DB::connection((string) Config::get('import.database.mongo.connection', 'mongodb'))
            ->table((string) Config::get('import.database.mongo.preview_sessions_collection', 'import_preview_sessions'));
    }

    private function snapshotRowsQuery()
    {
        return DB::connection((string) Config::get('import.database.mongo.connection', 'mongodb'))
            ->table((string) Config::get('import.database.mongo.preview_snapshot_rows_collection', 'import_preview_snapshot_rows'));
    }
}
