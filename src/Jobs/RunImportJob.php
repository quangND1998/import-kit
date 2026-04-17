<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Carbon\CarbonImmutable;
use Throwable;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\ImportRegistryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\DTO\ImportJobErrorData;
use Vendor\ImportKit\DTO\ImportJobResultRowData;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\Pipeline\ImportPipeline;
use Vendor\ImportKit\Support\ImportMode;
use Vendor\ImportKit\DTO\CommitResult;

final class RunImportJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $jobId,
        public readonly ?int $chunkOffset = null,
        public readonly ?int $chunkLimit = null,
        public readonly bool $finalizeAfterRun = true,
        public readonly bool $cleanupSourceOnSuccess = true,
        public readonly bool $rethrowOnFailure = false
    ) {
    }

    public function handle(
        ImportJobRepositoryInterface $jobs,
        PreviewSessionStoreInterface $sessions,
        ImportRegistryInterface $registry,
        SourceReaderResolverInterface $sourceReaderResolver,
        ImportPipeline $pipeline
    ): void
    {
        $job = $jobs->find($this->jobId);
        if ($job === null) {
            return;
        }

        if ($job->status === 'pending') {
            $jobs->update($this->jobId, [
                'status' => 'processing',
                'started_at' => CarbonImmutable::now(),
            ]);
        }

        try {
            $session = $sessions->find($job->sessionId);
            if ($session === null) {
                throw new \RuntimeException("Import preview session '{$job->sessionId}' not found.");
            }

            $module = $registry->get($job->kind);
            $sessionContext = $session->context;
            $runContext = ImportRunContext::from(
                tenantId: $session->tenantId,
                workspaceId: $session->workspaceId,
                context: is_array($sessionContext) ? $sessionContext : []
            );
            $sessionDisk = is_array($sessionContext) && isset($sessionContext['disk']) && is_string($sessionContext['disk'])
                ? $sessionContext['disk']
                : (string) Config::get('import.files.disk', 'local');
            $storedFile = new StoredFile(
                handle: $session->fileHandle,
                disk: $sessionDisk,
                path: $session->fileHandle,
                meta: [
                    'tenant_id' => $session->tenantId,
                    'workspace_id' => $session->workspaceId,
                    'context' => is_array($sessionContext) ? $sessionContext : [],
                ]
            );
            $reader = $sourceReaderResolver->resolve($storedFile, $job->kind, $module, $runContext);

            $result = $pipeline->run(
                mode: ImportMode::COMMIT,
                sessionId: $job->sessionId,
                module: $module,
                file: $storedFile,
                reader: $reader,
                runContext: $runContext,
                rowWindow: $this->rowWindow(),
                lineOffset: $this->chunkOffset ?? 0
            );
            if (!$result instanceof CommitResult) {
                throw new \RuntimeException('Commit pipeline returned invalid result.');
            }

            $rows = $result->rows ?? [];
            $rowPayloads = [];
            $errorPayloads = [];
            foreach ($rows as $row) {
                $rowPayloads[] = new ImportJobResultRowData(
                    jobId: $this->jobId,
                    line: $row->line,
                    status: $row->status,
                    payload: $row->toArray()
                );

                foreach ($row->errors as $error) {
                    $errorPayloads[] = new ImportJobErrorData(
                        jobId: $this->jobId,
                        line: $row->line,
                        field: $error->field,
                        code: $error->code,
                        message: $error->message,
                        payload: $row->toArray()
                    );
                }
            }

            $jobs->appendRows($this->jobId, $rowPayloads);
            $jobs->appendErrors($this->jobId, $errorPayloads);
            $jobs->incrementProgress($this->jobId, [
                'total_rows' => (int) ($result->summary['total_seen'] ?? 0),
                'processed_rows' => (int) ($result->summary['total_seen'] ?? 0),
                'ok_rows' => (int) ($result->summary['ok'] ?? 0),
                'error_rows' => (int) ($result->summary['error'] ?? 0),
                'skipped_blank_rows' => (int) ($result->summary['skipped_blank'] ?? 0),
            ]);
            if ($this->finalizeAfterRun) {
                $fresh = $jobs->find($this->jobId);
                $summary = $fresh !== null ? [
                    'total_seen' => $fresh->totalRows,
                    'ok' => $fresh->okRows,
                    'error' => $fresh->errorRows,
                    'skipped_blank' => $fresh->skippedBlankRows,
                ] : $result->summary;

                $jobs->update($this->jobId, [
                    'status' => 'completed',
                    'summary' => $summary,
                    'finished_at' => CarbonImmutable::now(),
                ]);

                if ($this->cleanupSourceOnSuccess) {
                    $this->cleanupSourceFile($sessionDisk, $session->fileHandle);
                }
                $sessions->updateStatus($job->sessionId, 'consumed');
            }
        } catch (Throwable $throwable) {
            $jobs->appendErrors($this->jobId, [
                new ImportJobErrorData(
                    jobId: $this->jobId,
                    line: null,
                    field: null,
                    code: 'job_exception',
                    message: $throwable->getMessage(),
                    payload: ['trace' => $throwable->getTraceAsString()]
                ),
            ]);

            if ($this->finalizeAfterRun) {
                $jobs->update($this->jobId, [
                    'status' => 'failed',
                    'summary' => ['message' => $throwable->getMessage()],
                    'finished_at' => CarbonImmutable::now(),
                ]);
            }

            if ($this->rethrowOnFailure) {
                throw $throwable;
            }
        }
    }

    private function rowWindow(): ?\Vendor\ImportKit\Support\RowWindow
    {
        if ($this->chunkOffset === null || $this->chunkLimit === null) {
            return null;
        }

        return new \Vendor\ImportKit\Support\RowWindow(
            offset: max(0, $this->chunkOffset),
            limit: max(1, $this->chunkLimit)
        );
    }

    private function cleanupSourceFile(string $disk, string $fileHandle): void
    {
        try {
            Storage::disk($disk)->delete($fileHandle);
        } catch (Throwable) {
            // Best-effort cleanup. Import result has already been committed.
        }
    }
}
