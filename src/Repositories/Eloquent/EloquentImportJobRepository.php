<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use JsonException;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\DTO\ImportJobErrorData;
use Vendor\ImportKit\DTO\ImportJobResultRowData;
use Vendor\ImportKit\Models\ImportJob;
use Vendor\ImportKit\Models\ImportJobError;
use Vendor\ImportKit\Models\ImportJobResultRow;
use Vendor\ImportKit\Support\RowWindow;

final class EloquentImportJobRepository implements ImportJobRepositoryInterface
{
    public function create(ImportJobData $job): ImportJobData
    {
        ImportJob::query()->create([
            'id' => $job->id,
            'kind' => $job->kind,
            'session_id' => $job->sessionId,
            'status' => $job->status,
            'submitted_by' => $job->submittedBy,
            'tenant_id' => $job->tenantId,
            'workspace_id' => $job->workspaceId,
            'total_rows' => $job->totalRows,
            'processed_rows' => $job->processedRows,
            'ok_rows' => $job->okRows,
            'error_rows' => $job->errorRows,
            'skipped_blank_rows' => $job->skippedBlankRows,
            'summary' => $job->summary,
            'started_at' => $job->startedAt,
            'finished_at' => $job->finishedAt,
        ]);

        return $job;
    }

    public function find(string $id): ?ImportJobData
    {
        $record = ImportJob::query()->find($id);
        if (!$record instanceof ImportJob) {
            return null;
        }

        return new ImportJobData(
            id: (string) $record->id,
            kind: (string) $record->kind,
            sessionId: (string) $record->session_id,
            status: (string) $record->status,
            submittedBy: $record->submitted_by !== null ? (int) $record->submitted_by : null,
            tenantId: $record->tenant_id !== null ? (int) $record->tenant_id : null,
            workspaceId: $record->workspace_id !== null ? (int) $record->workspace_id : null,
            totalRows: (int) $record->total_rows,
            processedRows: (int) $record->processed_rows,
            okRows: (int) $record->ok_rows,
            errorRows: (int) $record->error_rows,
            skippedBlankRows: (int) $record->skipped_blank_rows,
            summary: (array) ($record->summary ?? []),
            startedAt: $record->started_at ? CarbonImmutable::parse($record->started_at) : null,
            finishedAt: $record->finished_at ? CarbonImmutable::parse($record->finished_at) : null
        );
    }

    public function update(string $id, array $payload): void
    {
        if (isset($payload['summary']) && is_array($payload['summary'])) {
            $payload['summary'] = $this->encodeJson($payload['summary']);
        }

        ImportJob::query()->whereKey($id)->update($payload);
    }

    public function updateProgress(string $id, array $progress): void
    {
        ImportJob::query()->whereKey($id)->update($progress);
    }

    public function appendRows(string $id, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = CarbonImmutable::now();
        ImportJobResultRow::query()->insert(array_map(
            fn (ImportJobResultRowData $row): array => [
                'job_id' => $id,
                'line' => $row->line,
                'status' => $row->status,
                'payload' => $this->encodeJson($row->payload),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows
        ));
    }

    public function appendErrors(string $id, array $errors): void
    {
        if ($errors === []) {
            return;
        }

        $now = CarbonImmutable::now();
        ImportJobError::query()->insert(array_map(
            fn (ImportJobErrorData $error): array => [
                'job_id' => $id,
                'line' => $error->line,
                'field' => $error->field,
                'code' => $error->code,
                'message' => $error->message,
                'payload' => $this->encodeJson($error->payload),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $errors
        ));
    }

    public function getResultRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): array
    {
        $window = $rowWindow ?? new RowWindow(0, (int) Config::get('import.preview.default_per_page', 20));

        $query = ImportJobResultRow::query()->where('job_id', $id);
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
            ->map(static fn (ImportJobResultRow $row): array => (array) ($row->payload ?? []))
            ->all();

        $nextCursor = ($window->offset + count($rows)) < $filteredTotal
            ? (string) ($window->offset + $window->limit)
            : null;

        return [
            'rows' => $rows,
            'column_labels' => [],
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
