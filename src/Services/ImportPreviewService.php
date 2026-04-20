<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Illuminate\Support\Facades\Config;
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
        bool $validate = true
    ): PreviewResult {
        $session = $this->sessions->find($sessionId);
        if ($session instanceof PreviewSessionData && $session->kind !== $kind) {
            throw new \RuntimeException("Session '{$sessionId}' kind '{$session->kind}' does not match preview kind '{$kind}'.");
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
                throw new \RuntimeException("Import preview session '{$sessionId}' not found.");
            }
            $resolvedFile = $this->storedFileFromSession($session);
            $resolvedContext ??= $session->runContext();
        }

        $module = $this->registry->get($kind);
        $resolvedReader = $reader ?? $this->sourceReaderResolver->resolve($resolvedFile, $kind, $module, $resolvedContext);
        $result = $this->pipeline->run(
            ImportMode::PREVIEW,
            $sessionId,
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
            dataSource: 'file'
        );

        $this->sessions->savePreviewSnapshot(
            $sessionId,
            array_map(static fn ($row): array => $row->toArray(), $decorated->rows),
            $decorated->columnLabels,
            [
                'validated' => $validate,
                'source' => 'file',
            ]
        );

        return $decorated;
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
}
