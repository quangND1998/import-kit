<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Repositories\Mongo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\DTO\ImportJobErrorData;
use Vendor\ImportKit\DTO\ImportJobResultRowData;
use Vendor\ImportKit\Support\RowWindow;

final class MongoImportJobRepository implements ImportJobRepositoryInterface
{
    public function create(ImportJobData $job): ImportJobData
    {
        $this->query()->insert([
            '_id' => $job->id,
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
            'started_at' => $job->startedAt?->toDateTimeString(),
            'finished_at' => $job->finishedAt?->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $job;
    }

    public function find(string $id): ?ImportJobData
    {
        $record = (array) $this->query()->where('_id', $id)->first();
        if ($record === []) {
            return null;
        }

        return new ImportJobData(
            id: (string) ($record['_id'] ?? $id),
            kind: (string) ($record['kind'] ?? ''),
            sessionId: (string) ($record['session_id'] ?? ''),
            status: (string) ($record['status'] ?? 'pending'),
            submittedBy: isset($record['submitted_by']) ? (int) $record['submitted_by'] : null,
            tenantId: isset($record['tenant_id']) ? (int) $record['tenant_id'] : null,
            workspaceId: isset($record['workspace_id']) ? (int) $record['workspace_id'] : null,
            totalRows: (int) ($record['total_rows'] ?? 0),
            processedRows: (int) ($record['processed_rows'] ?? 0),
            okRows: (int) ($record['ok_rows'] ?? 0),
            errorRows: (int) ($record['error_rows'] ?? 0),
            skippedBlankRows: (int) ($record['skipped_blank_rows'] ?? 0),
            summary: (array) ($record['summary'] ?? []),
            startedAt: isset($record['started_at']) && $record['started_at'] ? CarbonImmutable::parse((string) $record['started_at']) : null,
            finishedAt: isset($record['finished_at']) && $record['finished_at'] ? CarbonImmutable::parse((string) $record['finished_at']) : null
        );
    }

    public function update(string $id, array $payload): void
    {
        $payload['updated_at'] = now()->toDateTimeString();
        $this->query()->where('_id', $id)->update($payload);
    }

    public function updateProgress(string $id, array $progress): void
    {
        $this->update($id, $progress);
    }

    public function incrementProgress(string $id, array $increments): void
    {
        $allowed = [
            'total_rows',
            'processed_rows',
            'ok_rows',
            'error_rows',
            'skipped_blank_rows',
        ];

        $update = [];
        foreach ($allowed as $field) {
            $value = (int) ($increments[$field] ?? 0);
            if ($value === 0) {
                continue;
            }

            $update[$field] = $value;
        }

        if ($update === []) {
            return;
        }

        foreach ($update as $field => $amount) {
            $this->query()->where('_id', $id)->increment($field, $amount);
        }
        $this->query()->where('_id', $id)->update([
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function appendRows(string $id, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $lines = array_values(array_unique(array_map(
            static fn (ImportJobResultRowData $row): int => $row->line,
            $rows
        )));
        if ($lines !== []) {
            $this->resultRowsQuery()->where('job_id', $id)->whereIn('line', $lines)->delete();
        }

        $now = now()->toDateTimeString();
        $payload = array_map(
            static fn (ImportJobResultRowData $row): array => [
                'job_id' => $id,
                'line' => $row->line,
                'status' => $row->status,
                'payload' => $row->payload,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows
        );

        $this->resultRowsQuery()->insert($payload);
    }

    public function appendErrors(string $id, array $errors): void
    {
        if ($errors === []) {
            return;
        }

        $now = now()->toDateTimeString();
        $payload = array_map(
            static fn (ImportJobErrorData $error): array => [
                'job_id' => $id,
                'line' => $error->line,
                'field' => $error->field,
                'code' => $error->code,
                'message' => $error->message,
                'payload' => $error->payload,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $errors
        );

        $this->errorsQuery()->insert($payload);
    }

    public function getResultRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): array
    {
        $window = $rowWindow ?? new RowWindow(0, (int) Config::get('import.preview.default_per_page', 20));
        $job = (array) $this->query()->where('_id', $id)->first();

        $query = $this->resultRowsQuery()->where('job_id', $id);
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

        return [
            'rows' => $rows,
            'column_labels' => [],
            'meta' => [
                'overall_total_rows' => isset($job['total_rows']) ? (int) $job['total_rows'] : null,
                'overall_ok_rows' => isset($job['ok_rows']) ? (int) $job['ok_rows'] : null,
                'overall_error_rows' => isset($job['error_rows']) ? (int) $job['error_rows'] : null,
                'skipped' => isset($job['skipped_blank_rows']) ? (int) $job['skipped_blank_rows'] : 0,
                'status' => isset($job['status']) ? (string) $job['status'] : null,
            ],
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

    private function query()
    {
        return DB::connection((string) Config::get('import.database.mongo.connection', 'mongodb'))
            ->table((string) Config::get('import.database.mongo.jobs_collection', 'import_jobs'));
    }

    private function resultRowsQuery()
    {
        return DB::connection((string) Config::get('import.database.mongo.connection', 'mongodb'))
            ->table((string) Config::get('import.database.mongo.job_result_rows_collection', 'import_job_result_rows'));
    }

    private function errorsQuery()
    {
        return DB::connection((string) Config::get('import.database.mongo.connection', 'mongodb'))
            ->table((string) Config::get('import.database.mongo.job_errors_collection', 'import_job_errors'));
    }
}
