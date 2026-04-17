<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\Jobs\RunImportJob;

final class ImportCommitService
{
    public function __construct(
        private readonly ImportJobRepositoryInterface $jobs,
        private readonly PreviewSessionStoreInterface $sessions
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

        Queue::push(new RunImportJob($job->id));

        return $job;
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
