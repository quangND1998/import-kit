<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Jobs;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\ImportRegistryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\DTO\ImportJobErrorData;
use Vendor\ImportKit\DTO\ImportJobResultRowData;
use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\Jobs\RunImportJob;
use Vendor\ImportKit\Pipeline\ImportPipeline;
use Vendor\ImportKit\Support\RowWindow;
use Vendor\ImportKit\Tests\Fixtures\FakeContextAwareCommitter;
use Vendor\ImportKit\Tests\Fixtures\FakeImportModule;
use Vendor\ImportKit\Tests\Fixtures\FakeSourceReader;

final class RunImportJobChunkTest extends TestCase
{
    public function testChunkModeAppendsRowsAndErrorsWithoutDataLoss(): void
    {
        $committer = new FakeContextAwareCommitter();
        $module = new FakeImportModule([], $committer);

        $jobs = new InMemoryImportJobRepository(
            new ImportJobData(
                id: 'job-1',
                kind: 'employee_update',
                sessionId: 'session-1',
                status: 'pending',
                submittedBy: null,
                tenantId: 10,
                workspaceId: 20
            )
        );
        $sessions = new InMemoryPreviewSessionStore(
            new PreviewSessionData(
                id: 'session-1',
                kind: 'employee_update',
                fileHandle: 'fixtures/import.csv',
                tenantId: 10,
                workspaceId: 20,
                context: ['disk' => 'local'],
                status: 'submitted',
                expiresAt: CarbonImmutable::now()->addHour()
            )
        );
        $registry = new InMemoryImportRegistry($module);
        $resolver = new InMemorySourceReaderResolver([
            ['employee_id' => 'E001', 'cf_income_123' => '100'],
            ['employee_id' => 'E002', 'cf_income_123' => 'abc'],
            ['employee_id' => 'E003', 'cf_income_123' => '200'],
            ['employee_id' => 'E004', 'cf_income_123' => 'xyz'],
        ]);

        $job1 = new RunImportJob(
            jobId: 'job-1',
            chunkOffset: 0,
            chunkLimit: 2,
            finalizeAfterRun: false,
            cleanupSourceOnSuccess: false,
            rethrowOnFailure: true
        );
        $job1->handle($jobs, $sessions, $registry, $resolver, new ImportPipeline());

        $job2 = new RunImportJob(
            jobId: 'job-1',
            chunkOffset: 2,
            chunkLimit: 2,
            finalizeAfterRun: false,
            cleanupSourceOnSuccess: false,
            rethrowOnFailure: true
        );
        $job2->handle($jobs, $sessions, $registry, $resolver, new ImportPipeline());

        $this->assertCount(4, $jobs->rows);
        $this->assertCount(2, $jobs->errors);
        $this->assertSame(4, $jobs->job->totalRows);
        $this->assertSame(4, $jobs->job->processedRows);
        $this->assertSame(2, $jobs->job->okRows);
        $this->assertSame(2, $jobs->job->errorRows);

        $lines = array_column($jobs->rows, 'line');
        sort($lines);
        $this->assertSame([2, 3, 4, 5], $lines);
    }
}

final class InMemoryImportJobRepository implements ImportJobRepositoryInterface
{
    public ImportJobData $job;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $errors = [];

    public function __construct(ImportJobData $job)
    {
        $this->job = $job;
    }

    public function create(ImportJobData $job): ImportJobData
    {
        $this->job = $job;

        return $job;
    }

    public function find(string $id): ?ImportJobData
    {
        return $this->job->id === $id ? $this->job : null;
    }

    public function update(string $id, array $payload): void
    {
        if ($this->job->id !== $id) {
            return;
        }

        $this->job = new ImportJobData(
            id: $this->job->id,
            kind: $this->job->kind,
            sessionId: $this->job->sessionId,
            status: (string) ($payload['status'] ?? $this->job->status),
            submittedBy: $this->job->submittedBy,
            tenantId: $this->job->tenantId,
            workspaceId: $this->job->workspaceId,
            totalRows: $this->job->totalRows,
            processedRows: $this->job->processedRows,
            okRows: $this->job->okRows,
            errorRows: $this->job->errorRows,
            skippedBlankRows: $this->job->skippedBlankRows,
            summary: isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : $this->job->summary,
            startedAt: $payload['started_at'] instanceof CarbonImmutable ? $payload['started_at'] : $this->job->startedAt,
            finishedAt: $payload['finished_at'] instanceof CarbonImmutable ? $payload['finished_at'] : $this->job->finishedAt
        );
    }

    public function updateProgress(string $id, array $progress): void
    {
        $this->incrementProgress($id, $progress);
    }

    public function incrementProgress(string $id, array $increments): void
    {
        if ($this->job->id !== $id) {
            return;
        }

        $this->job = new ImportJobData(
            id: $this->job->id,
            kind: $this->job->kind,
            sessionId: $this->job->sessionId,
            status: $this->job->status,
            submittedBy: $this->job->submittedBy,
            tenantId: $this->job->tenantId,
            workspaceId: $this->job->workspaceId,
            totalRows: $this->job->totalRows + (int) ($increments['total_rows'] ?? 0),
            processedRows: $this->job->processedRows + (int) ($increments['processed_rows'] ?? 0),
            okRows: $this->job->okRows + (int) ($increments['ok_rows'] ?? 0),
            errorRows: $this->job->errorRows + (int) ($increments['error_rows'] ?? 0),
            skippedBlankRows: $this->job->skippedBlankRows + (int) ($increments['skipped_blank_rows'] ?? 0),
            summary: $this->job->summary,
            startedAt: $this->job->startedAt,
            finishedAt: $this->job->finishedAt
        );
    }

    public function appendRows(string $id, array $rows): void
    {
        foreach ($rows as $row) {
            if (!$row instanceof ImportJobResultRowData) {
                continue;
            }

            $this->rows[] = [
                'line' => $row->line,
                'status' => $row->status,
                'payload' => $row->payload,
            ];
        }
    }

    public function appendErrors(string $id, array $errors): void
    {
        foreach ($errors as $error) {
            if (!$error instanceof ImportJobErrorData) {
                continue;
            }

            $this->errors[] = [
                'line' => $error->line,
                'code' => $error->code,
                'message' => $error->message,
            ];
        }
    }

    public function getResultRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): array
    {
        return [
            'rows' => [],
            'column_labels' => [],
            'pagination' => [
                'page' => 1,
                'per_page' => 20,
                'filtered_total' => 0,
                'next_cursor' => null,
            ],
            'filters' => [
                'status' => $status ?? 'all',
            ],
        ];
    }
}

final class InMemoryPreviewSessionStore implements PreviewSessionStoreInterface
{
    public function __construct(private PreviewSessionData $session)
    {
    }

    public function create(PreviewSessionData $session): PreviewSessionData
    {
        $this->session = $session;

        return $session;
    }

    public function find(string $id): ?PreviewSessionData
    {
        return $this->session->id === $id ? $this->session : null;
    }

    public function updateStatus(string $id, string $status): void
    {
    }

    public function updateFileContextAndStatus(string $id, string $fileHandle, array $context, string $status): void
    {
    }

    public function savePreviewSnapshot(string $id, array $rows, array $columnLabels = [], array $meta = []): void
    {
    }

    public function getPreviewSnapshot(string $id): ?array
    {
        return null;
    }

    public function getPreviewSnapshotRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): ?array
    {
        return null;
    }

    public function deleteExpiredPreviewSessions(): int
    {
        return 0;
    }
}

final class InMemoryImportRegistry implements ImportRegistryInterface
{
    public function __construct(private readonly FakeImportModule $module)
    {
    }

    public function get(string $kind): \Vendor\ImportKit\Contracts\ImportModuleInterface
    {
        return $this->module;
    }

    public function kinds(): array
    {
        return [$this->module->kind()];
    }
}

final class InMemorySourceReaderResolver implements SourceReaderResolverInterface
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function resolve(
        \Vendor\ImportKit\DTO\StoredFile $file,
        ?string $kind = null,
        ?\Vendor\ImportKit\Contracts\ImportModuleInterface $module = null,
        ?\Vendor\ImportKit\DTO\ImportRunContext $context = null
    ): \Vendor\ImportKit\Contracts\SourceReaderInterface {
        return new FakeSourceReader(
            headers: ['employee_id', 'cf_income_123'],
            rows: $this->rows,
            metadata: [
                'custom_field_map' => [
                    'cf_income_123' => [
                        'custom_field_id' => '123',
                        'column_index' => 26,
                        'label' => '26. Thu nhap | 123',
                        'data_type' => 'NUMBER',
                    ],
                ],
            ]
        );
    }
}

