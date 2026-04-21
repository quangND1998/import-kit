<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Throwable;
use Vendor\ImportKit\Contracts\CommitDispatchAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\ImportRegistryInterface;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\DTO\ImportJobErrorData;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\Jobs\RunImportJob;
use Vendor\ImportKit\Jobs\FinalizeImportJob;

final class ImportCommitService
{
    public function __construct(
        private readonly ImportJobRepositoryInterface $jobs,
        private readonly PreviewSessionStoreInterface $sessions,
        private readonly ImportRegistryInterface $registry,
        private readonly SourceReaderResolverInterface $sourceReaderResolver
    ) {
    }

    public function submit(
        string $kind,
        string $sessionId,
        ?ImportRunContext $runContext = null,
        ?int $submittedBy = null,
        ?int $tenantId = null,
        ?int $workspaceId = null
    ): ImportJobData {
        $session = $this->sessions->find($sessionId);
        if ($session === null) {
            throw new \RuntimeException("Import preview session '{$sessionId}' not found.");
        }

        if ($session->kind !== $kind) {
            throw new \RuntimeException("Session '{$sessionId}' kind '{$session->kind}' does not match submit kind '{$kind}'.");
        }

        $session = $this->ensureSubmitStorage($session, $runContext);
        $context = $this->resolveRunContext($session, $runContext);
        $module = $this->registry->get($kind);
        $dispatchOverrides = $this->resolveDispatchOverrides($module, $context);
        $effectiveTenantId = $runContext?->tenantId ?? $tenantId;
        $effectiveWorkspaceId = $runContext?->workspaceId ?? $workspaceId;
        $job = new ImportJobData(
            id: (string) Str::uuid(),
            kind: $kind,
            sessionId: $session->id,
            status: 'pending',
            submittedBy: $submittedBy,
            tenantId: $effectiveTenantId,
            workspaceId: $effectiveWorkspaceId
        );

        $job = $this->jobs->create($job);

        $dispatchMode = $dispatchOverrides['dispatch_mode'] ?? (string) Config::get('import.commit.dispatch_mode', 'bus_batch');
        if ($dispatchMode === 'bus_batch') {
            $chunkSize = max(1, (int) ($dispatchOverrides['batch']['chunk_size'] ?? Config::get('import.commit.batch.chunk_size', 500)));
            $allowFailures = (bool) ($dispatchOverrides['batch']['allow_failures'] ?? Config::get('import.commit.batch.allow_failures', false));
            $precount = (bool) Config::get('import.commit.batch.precount_logical_rows', true);

            $queueName = $dispatchOverrides['queue_name'] ?? null;
            if (!$precount) {
                $this->dispatchRunImportJob(new RunImportJob(
                    jobId: $job->id,
                    chunkOffset: 0,
                    chunkLimit: $chunkSize,
                    finalizeAfterRun: false,
                    cleanupSourceOnSuccess: false,
                    rethrowOnFailure: true,
                    chainRemainingChunks: true,
                    queueName: $queueName
                ), $queueName);
                $this->jobs->update($job->id, [
                    'summary' => [
                        'dispatch_mode' => 'bus_batch',
                        'queue_name' => $queueName,
                        'chunk_size' => $chunkSize,
                        'allow_failures' => $allowFailures,
                        'precount_logical_rows' => false,
                        'chunk_chaining' => true,
                    ],
                ]);
            } else {
                $chunkCount = $this->countChunks($session, $context, $chunkSize);
                if ($chunkCount <= 1) {
                    $this->dispatchRunImportJob(new RunImportJob($job->id, queueName: $queueName), $queueName);
                    $this->jobs->update($job->id, [
                        'summary' => [
                            'dispatch_mode' => 'bus_batch',
                            'queue_name' => $queueName,
                            'chunk_size' => $chunkSize,
                            'chunk_count' => 1,
                            'precount_logical_rows' => true,
                        ],
                    ]);
                } else {
                    $chunkJobs = [];
                    for ($index = 0; $index < $chunkCount; $index++) {
                        $offset = $index * $chunkSize;
                        $chunkJobs[] = new RunImportJob(
                            jobId: $job->id,
                            chunkOffset: $offset,
                            chunkLimit: $chunkSize,
                            finalizeAfterRun: false,
                            cleanupSourceOnSuccess: false,
                            rethrowOnFailure: true,
                            chainRemainingChunks: false,
                            queueName: $queueName
                        );
                    }

                    $pendingBatch = Bus::batch($chunkJobs)
                        ->allowFailures($allowFailures)
                        ->then(function () use ($job, $queueName): void {
                            $this->dispatchFinalizeImportJob(new FinalizeImportJob($job->id), $queueName);
                        })
                        ->catch(static function (Throwable $throwable) use ($job): void {
                            $repo = Container::getInstance()->make(ImportJobRepositoryInterface::class);
                            $fresh = $repo->find($job->id);
                            $repo->appendErrors($job->id, [
                                new ImportJobErrorData(
                                    jobId: $job->id,
                                    line: null,
                                    field: null,
                                    code: 'batch_exception',
                                    message: $throwable->getMessage(),
                                    payload: array_merge(
                                        ['trace' => $throwable->getTraceAsString()],
                                        [
                                            'session_id' => $fresh?->sessionId,
                                            'kind' => $fresh?->kind,
                                            'tenant_id' => $fresh?->tenantId,
                                            'workspace_id' => $fresh?->workspaceId,
                                        ]
                                    )
                                ),
                            ]);
                            $repo->update($job->id, [
                                'status' => 'failed',
                                'summary' => ['message' => $throwable->getMessage()],
                                'finished_at' => CarbonImmutable::now(),
                            ]);
                        });
                    if (is_string($queueName) && $queueName !== '') {
                        $pendingBatch->onQueue($queueName);
                    }
                    $batch = $pendingBatch->dispatch();

                    $this->jobs->update($job->id, [
                        'summary' => [
                            'dispatch_mode' => 'bus_batch',
                            'queue_name' => $queueName,
                            'batch_id' => $batch->id,
                            'chunk_size' => $chunkSize,
                            'allow_failures' => $allowFailures,
                            'chunk_count' => $chunkCount,
                            'precount_logical_rows' => true,
                        ],
                    ]);
                }
            }
        } else {
            $queueName = $dispatchOverrides['queue_name'] ?? null;
            $this->dispatchRunImportJob(new RunImportJob($job->id, queueName: $queueName), $queueName);
            $this->jobs->update($job->id, [
                'summary' => [
                    'dispatch_mode' => 'single',
                    'queue_name' => $queueName,
                ],
            ]);
        }

        return $job;
    }

    private function countChunks(PreviewSessionData $session, ImportRunContext $context, int $chunkSize): int
    {
        $sessionContext = $session->context;
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

        $totalRows = $this->countNonBlankRows($storedFile, $context, $session->kind);
        if ($totalRows <= 0) {
            return 1;
        }

        return (int) max(1, (int) ceil($totalRows / $chunkSize));
    }

    private function countNonBlankRows(StoredFile $file, ImportRunContext $context, string $kind): int
    {
        $module = $this->registry->get($kind);
        $reader = $this->sourceReaderResolver->resolve($file, $kind, $module, $context);
        $parser = $module->makeRowParser();

        $reader->open($file);
        try {
            $count = 0;
            foreach ($reader->rows() as $row) {
                $normalized = $parser->parse((array) $row, $context);
                if ($this->isBlankParsedRow($normalized)) {
                    continue;
                }

                ++$count;
            }
        } finally {
            $reader->close();
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function isBlankParsedRow(array $normalized): bool
    {
        foreach ($normalized as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function resolveRunContext(PreviewSessionData $session, ?ImportRunContext $runContext): ImportRunContext
    {
        if ($runContext !== null) {
            return $runContext;
        }

        return ImportRunContext::from(
            tenantId: $session->tenantId,
            workspaceId: $session->workspaceId,
            context: is_array($session->context) ? $session->context : []
        );
    }

    /**
     * @return array{
     *   dispatch_mode?: 'single'|'bus_batch',
     *   queue_name?: string,
     *   batch?: array{chunk_size?: int, allow_failures?: bool}
     * }
     */
    private function resolveDispatchOverrides(object $module, ImportRunContext $context): array
    {
        if (!$module instanceof CommitDispatchAwareImportModuleInterface) {
            return [];
        }

        $options = $module->commitDispatchOptions($context);
        if (!is_array($options)) {
            return [];
        }

        $dispatchMode = isset($options['dispatch_mode']) ? strtolower((string) $options['dispatch_mode']) : null;
        if (!in_array($dispatchMode, ['single', 'bus_batch'], true)) {
            $dispatchMode = null;
        }
        $queueName = isset($options['queue_name']) ? trim((string) $options['queue_name']) : null;
        if ($queueName === '') {
            $queueName = null;
        }

        $batch = is_array($options['batch'] ?? null) ? (array) $options['batch'] : [];
        $chunkSize = isset($batch['chunk_size']) ? max(1, (int) $batch['chunk_size']) : null;
        $allowFailures = array_key_exists('allow_failures', $batch) ? (bool) $batch['allow_failures'] : null;

        $normalized = [];
        if ($dispatchMode !== null) {
            $normalized['dispatch_mode'] = $dispatchMode;
        }
        if ($queueName !== null) {
            $normalized['queue_name'] = $queueName;
        }

        if ($chunkSize !== null || $allowFailures !== null) {
            $normalized['batch'] = array_filter([
                'chunk_size' => $chunkSize,
                'allow_failures' => $allowFailures,
            ], static fn ($value): bool => $value !== null);
        }

        return $normalized;
    }

    private function dispatchRunImportJob(RunImportJob $job, ?string $queueName): void
    {
        $pendingDispatch = RunImportJob::dispatch(
            $job->jobId,
            $job->chunkOffset,
            $job->chunkLimit,
            $job->finalizeAfterRun,
            $job->cleanupSourceOnSuccess,
            $job->rethrowOnFailure,
            $job->chainRemainingChunks,
            $job->queueName
        );

        if (is_string($queueName) && $queueName !== '') {
            $pendingDispatch->onQueue($queueName);
        }
    }

    private function dispatchFinalizeImportJob(FinalizeImportJob $job, ?string $queueName): void
    {
        $pendingDispatch = FinalizeImportJob::dispatch(
            $job->jobId,
            $job->cleanupSourceOnSuccess
        );

        if (is_string($queueName) && $queueName !== '') {
            $pendingDispatch->onQueue($queueName);
        }
    }

    private function ensureSubmitStorage(PreviewSessionData $session, ?ImportRunContext $runContext): PreviewSessionData
    {
        $currentContext = $session->context;
        $sourceDisk = $this->resolveSessionDisk($currentContext);
        $sourceHandle = $session->fileHandle;
        $submitDisk = $this->resolveSubmitDisk($runContext, $currentContext);
        $submitDirectory = trim((string) Config::get('import.submit.directory', Config::get('import.files.directory', 'import-kit')), '/');

        if ($submitDisk === '') {
            throw new \RuntimeException('Submit storage disk cannot be empty.');
        }

        if (!Storage::disk($sourceDisk)->exists($sourceHandle)) {
            throw new \RuntimeException("Source file '{$sourceHandle}' does not exist on disk '{$sourceDisk}'.");
        }

        // File already in submit storage (or was promoted previously), skip duplicate uploads.
        if ($sourceDisk === $submitDisk) {
            $nextContext = $this->buildSessionContext($currentContext, $submitDisk, $sourceDisk, $sourceHandle, $sourceHandle, false);
            $this->sessions->updateFileContextAndStatus($session->id, $sourceHandle, $nextContext, 'submitted');

            return new PreviewSessionData(
                id: $session->id,
                kind: $session->kind,
                fileHandle: $sourceHandle,
                tenantId: $session->tenantId,
                workspaceId: $session->workspaceId,
                context: $nextContext,
                status: 'submitted',
                expiresAt: $session->expiresAt
            );
        }

        $targetFileName = (string) Str::uuid() . '_' . basename($sourceHandle);
        $targetHandle = $submitDirectory !== '' ? $submitDirectory . '/' . $targetFileName : $targetFileName;
        $sourceStream = Storage::disk($sourceDisk)->readStream($sourceHandle);
        if ($sourceStream === false) {
            throw new \RuntimeException("Unable to open source stream for '{$sourceHandle}' on disk '{$sourceDisk}'.");
        }

        try {
            $written = Storage::disk($submitDisk)->writeStream($targetHandle, $sourceStream);
            if ($written !== true) {
                throw new \RuntimeException("Unable to write promoted file '{$targetHandle}' to disk '{$submitDisk}'.");
            }
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        $nextContext = $this->buildSessionContext($currentContext, $submitDisk, $sourceDisk, $sourceHandle, $targetHandle, true);
        $this->sessions->updateFileContextAndStatus($session->id, $targetHandle, $nextContext, 'submitted');

        return new PreviewSessionData(
            id: $session->id,
            kind: $session->kind,
            fileHandle: $targetHandle,
            tenantId: $session->tenantId,
            workspaceId: $session->workspaceId,
            context: $nextContext,
            status: 'submitted',
            expiresAt: $session->expiresAt
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildSessionContext(
        array $context,
        string $submitDisk,
        string $sourceDisk,
        string $sourceHandle,
        string $submitHandle,
        bool $promoted
    ): array {
        $context['disk'] = $submitDisk;
        $context['submit_storage'] = [
            'disk' => $submitDisk,
            'handle' => $submitHandle,
            'promoted' => $promoted,
            'promoted_from_disk' => $sourceDisk,
            'promoted_from_handle' => $sourceHandle,
            'promoted_at' => CarbonImmutable::now()->toISOString(),
        ];

        return $context;
    }

    /**
     * @param array<string, mixed> $sessionContext
     */
    private function resolveSessionDisk(array $sessionContext): string
    {
        $contextDisk = Arr::get($sessionContext, 'disk');
        if (is_string($contextDisk) && $contextDisk !== '') {
            return $contextDisk;
        }

        return (string) Config::get('import.files.disk', 'local');
    }

    /**
     * @param array<string, mixed> $sessionContext
     */
    private function resolveSubmitDisk(?ImportRunContext $runContext, array $sessionContext): string
    {
        $runContextDisk = Arr::get($runContext?->context ?? [], 'submit_disk');
        if (is_string($runContextDisk) && $runContextDisk !== '') {
            return $runContextDisk;
        }

        $sessionSubmitDisk = Arr::get($sessionContext, 'submit_disk');
        if (is_string($sessionSubmitDisk) && $sessionSubmitDisk !== '') {
            return $sessionSubmitDisk;
        }

        return (string) Config::get('import.submit.disk', 's3_happytime');
    }
}
