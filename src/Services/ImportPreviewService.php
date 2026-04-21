<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Vendor\ImportKit\Contracts\FileStoreInterface;
use Vendor\ImportKit\Contracts\ImportRegistryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\PreviewSessionData;
use Vendor\ImportKit\DTO\PreviewResult;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\Pipeline\ImportPipeline;
use Vendor\ImportKit\Support\ImportKitTranslator;
use Vendor\ImportKit\Support\ImportMode;
use Vendor\ImportKit\Support\RowWindow;

final class ImportPreviewService
{
    public function __construct(
        private readonly ImportRegistryInterface $registry,
        private readonly ImportPipeline $pipeline,
        private readonly ColumnLabelService $columnLabelService,
        private readonly PreviewSessionStoreInterface $sessions,
        private readonly SourceReaderResolverInterface $sourceReaderResolver,
        private readonly FileStoreInterface $fileStore
    ) {
    }

    public function preview(
        string $kind,
        string $sessionId,
        mixed $file = null,
        ?ImportRunContext $runContext = null,
        ?SourceReaderInterface $reader = null,
        ?RowWindow $rowWindow = null,
        bool $validate = true,
        ?string $status = null
    ): PreviewResult {
        $requestedSessionId = trim($sessionId);
        $session = $requestedSessionId !== '' ? $this->sessions->find($requestedSessionId) : null;
        if ($session instanceof PreviewSessionData && $session->kind !== $kind) {
            throw new \RuntimeException("Session '{$requestedSessionId}' kind '{$session->kind}' does not match preview kind '{$kind}'.");
        }

        $resolvedContext = $runContext;
        if ($this->isUploadedFile($file)) {
            /** @var \Illuminate\Http\UploadedFile $file */
            $storedFile = $this->fileStore->putUploadedFile($file, [
                'tenant_id' => $runContext?->tenantId,
                'workspace_id' => $runContext?->workspaceId,
                'context' => $runContext?->context ?? [],
            ]);
            if ($session instanceof PreviewSessionData) {
                $this->syncSessionFile($session, $storedFile, $runContext);
            }
            $resolvedFile = $storedFile;
        } elseif ($file instanceof StoredFile) {
            if ($session instanceof PreviewSessionData) {
                $this->syncSessionFile($session, $file, $runContext);
            }
            $resolvedFile = $file;
        } else {
            if ($file !== null) {
                throw new \InvalidArgumentException('preview $file must be null, StoredFile, or Illuminate\\Http\\UploadedFile.');
            }
            if (!$session instanceof PreviewSessionData) {
                throw new \RuntimeException("Import preview session '{$requestedSessionId}' not found.");
            }
            $resolvedFile = $this->storedFileFromSession($session);
            $resolvedContext ??= $session->runContext();
        }

        if (!$session instanceof PreviewSessionData) {
            if ($requestedSessionId !== '') {
                throw new \RuntimeException("Import preview session '{$requestedSessionId}' not found.");
            }
            $session = $this->createPreviewSession($kind, $resolvedFile, $resolvedContext);
            $requestedSessionId = $session->id;
        }

        $resolvedContext ??= $session->runContext();
        $module = $this->registry->get($kind);
        $resolvedReader = $reader ?? $this->sourceReaderResolver->resolve($resolvedFile, $kind, $module, $resolvedContext);
        $result = $this->pipeline->run(
            ImportMode::PREVIEW,
            $session->id,
            $module,
            $resolvedFile,
            $resolvedReader,
            $resolvedContext,
            $rowWindow,
            $validate
        );

        if (!$result instanceof PreviewResult) {
            throw new \RuntimeException(ImportKitTranslator::invalidPreviewResult());
        }

        $decorated = new PreviewResult(
            sessionId: $result->sessionId,
            kind: $result->kind,
            summary: $result->summary,
            pagination: $result->pagination,
            rows: $result->rows,
            columnLabels: $this->columnLabelService->labelsFor($module),
            validated: $validate,
            dataSource: 'file',
            filters: ['status' => 'all']
        );

        $this->sessions->savePreviewSnapshot(
            $session->id,
            array_map(static fn ($row): array => $row->toArray(), $decorated->rows),
            $decorated->columnLabels,
            [
                'validated' => $validate,
                'source' => 'file',
            ]
        );

        $resolvedStatus = $this->normalizeStatus($status);
        if ($resolvedStatus === null) {
            return $decorated;
        }

        $filteredRows = array_values(array_filter(
            $decorated->rows,
            static fn ($row): bool => $row->status === $resolvedStatus
        ));

        $filteredSummary = $decorated->summary;
        $filteredSummary['total_seen'] = count($filteredRows);
        $filteredSummary['valid'] = count(array_filter(
            $filteredRows,
            static fn ($row): bool => $row->status === 'ok'
        ));
        $filteredSummary['invalid'] = count(array_filter(
            $filteredRows,
            static fn ($row): bool => $row->status === 'error'
        ));

        $filteredPagination = $decorated->pagination;
        $filteredPagination['filtered_total'] = count($filteredRows);

        return new PreviewResult(
            sessionId: $decorated->sessionId,
            kind: $decorated->kind,
            summary: $filteredSummary,
            pagination: $filteredPagination,
            rows: $filteredRows,
            columnLabels: $decorated->columnLabels,
            validated: $decorated->validated,
            dataSource: $decorated->dataSource,
            filters: ['status' => $resolvedStatus]
        );
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim($status));
        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        return $normalized;
    }

    private function storedFileFromSession(PreviewSessionData $session): StoredFile
    {
        if ($session->fileHandle === '') {
            throw new \RuntimeException("Session '{$session->id}' does not have an uploaded file.");
        }

        $sessionContext = is_array($session->context) ? $session->context : [];
        $disk = (string) ($sessionContext['disk'] ?? Config::get('import.files.disk', 'local'));

        return new StoredFile(
            handle: $session->fileHandle,
            disk: $disk,
            path: $session->fileHandle,
            meta: [
                'tenant_id' => $session->tenantId,
                'workspace_id' => $session->workspaceId,
                'context' => $sessionContext,
            ]
        );
    }

    private function syncSessionFile(PreviewSessionData $session, StoredFile $file, ?ImportRunContext $runContext): void
    {
        $context = is_array($session->context) ? $session->context : [];
        $context['disk'] = $file->disk;
        $context['file_handle'] = $file->handle;
        $context['file_path'] = $file->path;

        if ($runContext instanceof ImportRunContext) {
            $context = array_merge($context, $runContext->context);
        }

        $this->sessions->updateFileContextAndStatus($session->id, $file->handle, $context, 'uploaded');
    }

    private function isUploadedFile(mixed $file): bool
    {
        return is_object($file) && is_a($file, 'Illuminate\Http\UploadedFile');
    }

    private function createPreviewSession(string $kind, StoredFile $file, ?ImportRunContext $runContext): PreviewSessionData
    {
        $context = $runContext?->context ?? [];
        $context['disk'] = $file->disk;
        $context['file_handle'] = $file->handle;
        $context['file_path'] = $file->path;

        $expiresMinutes = max(1, (int) Config::get('import.preview.expires_minutes', 120));
        $session = new PreviewSessionData(
            id: (string) Str::uuid(),
            kind: $kind,
            fileHandle: $file->handle,
            tenantId: $runContext?->tenantId,
            workspaceId: $runContext?->workspaceId,
            context: $context,
            status: 'uploaded',
            expiresAt: CarbonImmutable::now()->addMinutes($expiresMinutes)
        );

        return $this->sessions->create($session);
    }
}
