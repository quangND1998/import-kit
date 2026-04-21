<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vendor\ImportKit\Contracts\FileStoreInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\Exceptions\InvalidTemplateException;
use Vendor\ImportKit\Modules\Samples\EmployeeImportTestModule;
use Vendor\ImportKit\Services\ImportCommitService;
use Vendor\ImportKit\Services\ImportPreviewService;
use Vendor\ImportKit\Support\RowWindow;

final class EmployeeImportTestController
{
    public function __construct(
        private readonly ImportPreviewService $previewService,
        private readonly ImportCommitService $commitService,
        private readonly FileStoreInterface $fileStore
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
                'tenant_id' => ['sometimes', 'nullable', 'integer'],
                'workspace_id' => ['sometimes', 'nullable', 'integer'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            ],
            [
                'file.required' => (string) \__('import_kit::test_import.validation.file_required'),
                'file.file' => (string) \__('import_kit::test_import.validation.file_file'),
                'file.mimes' => (string) \__('import_kit::test_import.validation.file_mimes'),
                'file.max' => (string) \__('import_kit::test_import.validation.file_max'),
                'tenant_id.integer' => (string) \__('import_kit::test_import.validation.tenant_id_integer'),
                'workspace_id.integer' => (string) \__('import_kit::test_import.validation.workspace_id_integer'),
                'page.integer' => (string) \__('import_kit::test_import.validation.page_integer'),
                'page.min' => (string) \__('import_kit::test_import.validation.page_min'),
                'per_page.integer' => (string) \__('import_kit::test_import.validation.per_page_integer'),
                'per_page.min' => (string) \__('import_kit::test_import.validation.per_page_min'),
                'per_page.max' => (string) \__('import_kit::test_import.validation.per_page_max'),
            ]
        );

        $uploaded = $request->file('file');
        if ($uploaded === null) {
            return response()->json([
                'ok' => false,
                'message' => (string) \__('import_kit::test_import.preview_file_required'),
            ], 422);
        }

        $stored = $this->fileStore->putUploadedFile($uploaded, [
            'tenant_id' => $validated['tenant_id'] ?? null,
            'workspace_id' => $validated['workspace_id'] ?? null,
            'context' => [],
        ]);

        return $this->runPreview(
            $stored,
            ImportRunContext::from(
                isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null,
                isset($validated['workspace_id']) ? (int) $validated['workspace_id'] : null,
                []
            ),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? (int) config('import.preview.default_per_page', 20))
        );
    }

    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'session_id' => ['required', 'string'],
                'tenant_id' => ['sometimes', 'nullable', 'integer'],
                'workspace_id' => ['sometimes', 'nullable', 'integer'],
                'submitted_by' => ['sometimes', 'nullable', 'integer'],
            ],
            [
                'session_id.required' => (string) \__('import_kit::test_import.validation.session_id_required'),
                'tenant_id.integer' => (string) \__('import_kit::test_import.validation.tenant_id_integer'),
                'workspace_id.integer' => (string) \__('import_kit::test_import.validation.workspace_id_integer'),
                'submitted_by.integer' => (string) \__('import_kit::test_import.validation.submitted_by_integer'),
            ]
        );

        $sessionId = (string) ($validated['session_id'] ?? '');

        $tenantId = array_key_exists('tenant_id', $validated) ? (is_null($validated['tenant_id']) ? null : (int) $validated['tenant_id']) : null;
        $workspaceId = array_key_exists('workspace_id', $validated) ? (is_null($validated['workspace_id']) ? null : (int) $validated['workspace_id']) : null;
        $submittedBy = array_key_exists('submitted_by', $validated) ? (is_null($validated['submitted_by']) ? null : (int) $validated['submitted_by']) : null;

        try {
            $job = $this->commitService->submit(
                kind: EmployeeImportTestModule::KIND,
                sessionId: $sessionId,
                runContext: ImportRunContext::from($tenantId, $workspaceId, []),
                submittedBy: $submittedBy,
                tenantId: $tenantId,
                workspaceId: $workspaceId
            );

            return response()->json([
                'ok' => true,
                'message' => (string) \__('import_kit::test_import.submit_completed'),
                'data' => [
                    'import_job_id' => $job->id,
                    'kind' => $job->kind,
                    'session_id' => $job->sessionId,
                    'status' => $job->status,
                    'submitted_by' => $job->submittedBy,
                    'tenant_id' => $job->tenantId,
                    'workspace_id' => $job->workspaceId,
                    'summary' => $job->summary,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function previewFixture(Request $request): JsonResponse
    {
        $path = (string) config('import.test.employee_fixture_absolute_path', '');
        if ($path === '' || !is_file($path)) {
            return response()->json([
                'ok' => false,
                'message' => (string) \__('import_kit::test_import.fixture_not_configured'),
            ], 404);
        }

        $stored = new StoredFile(
            handle: basename($path),
            disk: 'local',
            path: basename($path),
            meta: [
                'absolute_path' => $path,
                'tenant_id' => 1,
                'workspace_id' => 1,
                'context' => [],
            ]
        );

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', (int) config('import.preview.default_per_page', 20));

        return $this->runPreview(
            $stored,
            ImportRunContext::from(1, 1, []),
            max(1, $page),
            max(1, min(200, $perPage))
        );
    }

    private function runPreview(
        StoredFile $file,
        ImportRunContext $context,
        int $page,
        int $perPage
    ): JsonResponse {
        $sessionId = '';

        try {
            $result = $this->previewService->preview(
                kind: EmployeeImportTestModule::KIND,
                sessionId: $sessionId,
                file: $file,
                runContext: $context,
                reader: null,
                rowWindow: RowWindow::fromPage($page, $perPage),
                validate: true
            );

            return response()->json([
                'ok' => true,
                'message' => (string) \__('import_kit::test_import.preview_completed'),
                'data' => $result->toArray(),
            ]);
        } catch (InvalidTemplateException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errorsToArray(),
            ], 422);
        }
    }
}
