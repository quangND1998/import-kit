<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Illuminate\Support\Facades\Config;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\Support\RowWindow;

final class ImportResultService
{
    public function __construct(
        private readonly ImportJobRepositoryInterface $jobs,
        private readonly PreviewSessionStoreInterface $sessions,
        private readonly ImportPreviewService $previewService,
        private readonly ImportResponseFormatter $responseFormatter
    ) {
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   column_labels: array<string, string>,
     *   meta: array<string, mixed>,
     *   pagination: array{page: int, per_page: int, filtered_total: int, next_cursor: ?string},
     *   filters: array{status: string}
     * }|null
     */
    public function previewRows(
        string $sessionId,
        ?string $status = null,
        ?RowWindow $rowWindow = null,
        bool $validate = false
    ): ?array
    {
        if ($validate) {
            $session = $this->sessions->find($sessionId);
            if ($session === null) {
                return null;
            }

            $sessionContext = $session->context;
            $sessionDisk = is_array($sessionContext) && isset($sessionContext['disk']) && is_string($sessionContext['disk'])
                ? $sessionContext['disk']
                : (string) Config::get('import.files.disk', 'local');

            $file = new StoredFile(
                handle: $session->fileHandle,
                disk: $sessionDisk,
                path: $session->fileHandle,
                meta: [
                    'tenant_id' => $session->tenantId,
                    'workspace_id' => $session->workspaceId,
                    'context' => is_array($sessionContext) ? $sessionContext : [],
                ]
            );

            $runContext = ImportRunContext::from(
                tenantId: $session->tenantId,
                workspaceId: $session->workspaceId,
                context: is_array($sessionContext) ? $sessionContext : []
            );

            // Refresh full snapshot with latest validation before paginating.
            $this->previewService->preview(
                kind: $session->kind,
                sessionId: $sessionId,
                file: $file,
                runContext: $runContext,
                rowWindow: null,
                validate: true
            );
        }

        $result = $this->sessions->getPreviewSnapshotRows($sessionId, $this->normalizeStatus($status), $rowWindow);
        if ($result === null) {
            return null;
        }

        return $this->responseFormatter->format(
            mode: 'preview',
            id: $sessionId,
            rows: (array) ($result['rows'] ?? []),
            pagination: (array) ($result['pagination'] ?? []),
            columnLabels: (array) ($result['column_labels'] ?? []),
            meta: (array) ($result['meta'] ?? []),
            filters: (array) ($result['filters'] ?? ['status' => 'all'])
        );
    }

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page: int, per_page: int, filtered_total: int, next_cursor: ?string},
     *   filters: array{status: string}
     * }
     */
    public function resultRows(string $jobId, ?string $status = null, ?RowWindow $rowWindow = null): array
    {
        $result = $this->jobs->getResultRows($jobId, $this->normalizeStatus($status), $rowWindow);
        $meta = array_merge(
            (array) ($result['meta'] ?? []),
            [
                'validated' => true,
                'source' => 'job_result',
            ]
        );
        return $this->responseFormatter->format(
            mode: 'result',
            id: $jobId,
            rows: (array) ($result['rows'] ?? []),
            pagination: (array) ($result['pagination'] ?? []),
            columnLabels: (array) ($result['column_labels'] ?? []),
            meta: $meta,
            filters: (array) ($result['filters'] ?? ['status' => 'all'])
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
}
