<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
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

        $dispatchMode = $dispatchOverrides['dispatch_mode'] ?? (string) Config::get('import.commit.dispatch_mode', 'single');
        if ($dispatchMode === 'bus_batch') {
            $chunkSize = max(1, (int) ($dispatchOverrides['batch']['chunk_size'] ?? Config::get('import.commit.batch.chunk_size', 500)));
            $allowFailures = (bool) ($dispatchOverrides['batch']['allow_failures'] ?? Config::get('import.commit.batch.allow_failures', false));
            $chunkCount = $this->countChunks($session, $context, $chunkSize);
            if ($chunkCount <= 1) {
                Queue::push(new RunImportJob($job->id));
                $this->jobs->update($job->id, [
                    'summary' => [
                        'dispatch_mode' => 'bus_batch',
                        'chunk_size' => $chunkSize,
                        'chunk_count' => 1,
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
                        rethrowOnFailure: true
                    );
                }

                $batch = Bus::batch($chunkJobs)
                    ->allowFailures($allowFailures)
                    ->then(static function () use ($job): void {
                        Queue::push(new FinalizeImportJob($job->id));
                    })
                    ->catch(static function (Throwable $throwable) use ($job): void {
                        $repo = Container::getInstance()->make(ImportJobRepositoryInterface::class);
                        $repo->appendErrors($job->id, [
                            new ImportJobErrorData(
                                jobId: $job->id,
                                line: null,
                                field: null,
                                code: 'batch_exception',
                                message: $throwable->getMessage(),
                                payload: ['trace' => $throwable->getTraceAsString()]
                            ),
                        ]);
                        $repo->update($job->id, [
                            'status' => 'failed',
                            'summary' => ['message' => $throwable->getMessage()],
                            'finished_at' => CarbonImmutable::now(),
                        ]);
                    })
                    ->dispatch();

                $this->jobs->update($job->id, [
                    'summary' => [
                        'dispatch_mode' => 'bus_batch',
                        'batch_id' => $batch->id,
                        'chunk_size' => $chunkSize,
                        'allow_failures' => $allowFailures,
                        'chunk_count' => $chunkCount,
                    ],
                ]);
            }
        } else {
            Queue::push(new RunImportJob($job->id));
            $this->jobs->update($job->id, [
                'summary' => [
                    'dispatch_mode' => 'single',
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

        $reader->open($file);
        try {
            $count = 0;
            foreach ($reader->rows() as $row) {
                if ($this->isBlankRow((array) $row)) {
                    continue;
                }

                $count++;
            }
        } finally {
            $reader->close();
        }

        return $count;
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

        $batch = is_array($options['batch'] ?? null) ? (array) $options['batch'] : [];
        $chunkSize = isset($batch['chunk_size']) ? max(1, (int) $batch['chunk_size']) : null;
        $allowFailures = array_key_exists('allow_failures', $batch) ? (bool) $batch['allow_failures'] : null;

        $normalized = [];
        if ($dispatchMode !== null) {
            $normalized['dispatch_mode'] = $dispatchMode;
        }

        if ($chunkSize !== null || $allowFailures !== null) {
            $normalized['batch'] = array_filter([
                'chunk_size' => $chunkSize,
                'allow_failures' => $allowFailures,
            ], static fn ($value): bool => $value !== null);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
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
