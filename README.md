# Import Kit

Reusable Laravel import package with preview + async commit pipeline.

Gợi ý ngôn ngữ / Language note:
- Tài liệu viết theo kiểu song ngữ ngắn gọn (Viet + English keywords).
- Code examples ưu tiên tiếng Anh để copy/paste.

---

## 1) Mục tiêu package / What this package solves

Package này giúp bạn xây import pipeline theo pattern:
- Upload file -> Preview validation result.
- Confirm import -> Queue async commit.
- Track status + errors + result rows.
- Hỗ trợ custom field động theo workspace/tenant.
- Hỗ trợ strict template mapping (header row, column order, custom header format).

Phù hợp khi bạn muốn:
- Tách business rule ra khỏi controller lớn.
- Dùng chung import infra cho nhiều domain (`employee`, `user`, `cost_center`, ...).
- Có flow polling kết quả import job.

---

## 2) Kiến trúc tổng quan / High-level architecture

Core components:
- `ImportModuleInterface`: module business cho từng `kind`.
- `ImportPipeline`: parser -> validator -> mapper -> committer.
- `ImportPreviewService`: chạy preview mode.
- `ImportCommitService`: tạo job async commit.
- `RunImportJob`: worker consume queue, chạy commit mode.
- `SourceReaderResolver`: chọn `CsvSourceReader` hoặc `SpreadsheetSourceReader`.
- `ConfigurableHeaderLocator`: strict header/custom field validation metadata.

Data stores:
- MySQL hoặc Mongo cho:
  - preview sessions
  - import jobs
  - import errors
  - import result rows

---

## 3) Requirements

- PHP `>=8.0`
- Laravel `>=8.0`
- `phpoffice/phpspreadsheet` cho `xlsx/xls`

---

## 4) Installation

```bash
composer require happytime/import-kit
```

Publish config:

```bash
php artisan vendor:publish --provider="Vendor\\ImportKit\\ImportKitServiceProvider" --tag=import-kit-config
```

Publish migrations:

```bash
php artisan vendor:publish --provider="Vendor\\ImportKit\\ImportKitServiceProvider" --tag=import-kit-migrations
php artisan migrate
```

Queue worker:

```bash
php artisan queue:work
```

---

## 5) Quick start (5 bước) / Quick start in 5 steps

1. Tạo module class implement `ImportModuleInterface`.
2. Đăng ký module vào `ImportRegistryInterface`.
3. Gọi preview service để validate file.
4. Tạo preview session trong app layer (nếu app bạn đang quản lý session id).
5. Submit commit job và poll status.

Chi tiết ở các mục bên dưới.

---

## 6) Cấu hình package / Package config

File: `config/import.php`

Các key quan trọng:
- `storage_driver`: `mysql` | `mongo`
- `database.*`: table/collection names
- `files.disk`, `files.directory`
- `preview.expires_minutes`, `preview.default_per_page`
- `column_labels`
- `header.default` (fallback policy, không phải nơi ưu tiên)

### Header config philosophy

Bạn không cần khai báo policy theo `kinds` trong config.

Khuyến nghị:
- Define header policy trong module class (code-first).
- `config.import.header.default` chỉ là fallback để backward-compatible.

---

## 7) Hướng dẫn implement module chi tiết / Detailed module implementation

### 7.1 Tạo module cơ bản

```php
use Vendor\ImportKit\Contracts\ImportModuleInterface;
use Vendor\ImportKit\Contracts\ContextAwareRowParserInterface;
use Vendor\ImportKit\Contracts\ContextAwareRowValidatorInterface;
use Vendor\ImportKit\Contracts\ContextAwareRowMapperInterface;
use Vendor\ImportKit\Contracts\RowParserInterface;
use Vendor\ImportKit\Contracts\RowValidatorInterface;
use Vendor\ImportKit\Contracts\RowMapperInterface;
use Vendor\ImportKit\Contracts\RowCommitterInterface;

final class UserImportModule implements ImportModuleInterface
{
    public function kind(): string
    {
        return 'user_import';
    }

    public function requiredHeaders(): array
    {
        return ['employee_id', 'full_name'];
    }

    public function optionalHeaders(): array
    {
        return [];
    }

    public function columnLabels(): array
    {
        return [
            'employee_id' => 'Mã định danh',
            'full_name' => 'Họ và tên',
        ];
    }

    public function makeRowParser(): RowParserInterface { /* parser or context-aware parser */ }
    public function makeRowValidator(): RowValidatorInterface { /* validator or context-aware validator */ }
    public function makeRowMapper(): RowMapperInterface { /* mapper or context-aware mapper */ }
    public function makeRowCommitter(): RowCommitterInterface { /* ... */ }
}
```

Context-aware contracts available:
- `ContextAwareRowParserInterface::parseWithContext(array $row, ImportRunContext $context): array`
- `ContextAwareRowValidatorInterface::validateWithContext(array $normalizedRow, ImportRunContext $context): ValidationResult`
- `ContextAwareRowMapperInterface::mapWithContext(array $validatedRow, ImportRunContext $context): array`
- `ContextAwareRowCommitterInterface::commitWithContext(array $mappedRow, ImportRunContext $context): void`

Pipeline behavior:
- Neu component implement version context-aware, pipeline se uu tien goi method `*WithContext(...)`.
- Neu khong, pipeline tiep tuc goi method cu (`parse`, `validate`, `map`, `commit`) de giu backward-compatible.

### 7.2 Strict header policy trong module (recommended)

Implement interface sau:
- `HeaderPolicyAwareImportModuleInterface`

```php
use Vendor\ImportKit\Contracts\HeaderPolicyAwareImportModuleInterface;
use Vendor\ImportKit\Contracts\CommitDispatchAwareImportModuleInterface;
use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\DTO\ImportRunContext;

final class UserImportModule implements ImportModuleInterface, HeaderPolicyAwareImportModuleInterface, CommitDispatchAwareImportModuleInterface
{
    public function headerPolicy(ImportRunContext $context): HeaderPolicy
    {
        return new HeaderPolicy(
            headerRowIndex: 2,
            requiredHeaders: ['mã_định_danh_nhân_viên', 'họ_và_tên*'],
            strictOrder: true,
            strictCoreColumns: [
                1 => 'Mã định danh nhân viên',
                2 => 'Họ và tên*',
            ],
            customFieldStartColumn: 26,
            customFieldPattern: '/\|\s*(?<id>[A-Za-z0-9_-]+)\s*$/',
            normalizeMode: 'snake'
        );
    }

    public function commitDispatchOptions(ImportRunContext $context): array
    {
        return [
            'dispatch_mode' => 'bus_batch',
            'batch' => [
                'chunk_size' => 300,
                'allow_failures' => false,
            ],
        ];
    }
}
```

Lưu ý quan trọng:
- `strictCoreColumns` compare exact string (`===`), nên tiếng Việt có dấu được hỗ trợ.
- Nếu file sai dấu/space/* -> invalid template.

### 7.3 Dynamic custom fields từ DB trong module

Implement:
- `CustomFieldCatalogAwareImportModuleInterface`

```php
use Vendor\ImportKit\Contracts\CustomFieldCatalogAwareImportModuleInterface;
use Vendor\ImportKit\DTO\CustomFieldDefinition;
use Vendor\ImportKit\DTO\ImportRunContext;

final class UserImportModule implements ImportModuleInterface, CustomFieldCatalogAwareImportModuleInterface
{
    public function activeCustomFields(ImportRunContext $context): array
    {
        // Ví dụ query DB theo workspace:
        // $rows = CustomField::query()
        //     ->where('workspace_id', $context->workspaceId)
        //     ->where('is_active', true)
        //     ->get();

        // return $rows->map(fn($row) => new CustomFieldDefinition(
        //     id: (string) $row->id,
        //     title: (string) $row->title,
        //     dataType: (string) $row->data_type
        // ))->all();

        return [
            new CustomFieldDefinition(id: '123', title: 'Thu nhập', dataType: 'NUMBER'),
            new CustomFieldDefinition(id: '124', title: 'Ngày vào công ty', dataType: 'DATE'),
        ];
    }
}
```

### 7.4 Validate custom field values theo datatype

Implement:
- `CustomFieldAwareImportModuleInterface`

Pipeline sẽ truyền custom values đã parse vào module để validate row-level.

```php
use Vendor\ImportKit\Contracts\CustomFieldAwareImportModuleInterface;
use Vendor\ImportKit\DTO\CustomFieldValue;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationError;

final class UserImportModule implements ImportModuleInterface, CustomFieldAwareImportModuleInterface
{
    public function validateCustomFieldValues(array $normalizedRow, array $customFieldValues, ImportRunContext $context): array
    {
        $errors = [];

        foreach ($customFieldValues as $item) {
            if (!$item instanceof CustomFieldValue) {
                continue;
            }

            $type = (string) ($item->meta['data_type'] ?? '');
            if ($type === 'NUMBER' && $item->value !== null && $item->value !== '' && !is_numeric((string) $item->value)) {
                $errors[] = new ValidationError(
                    field: (string) $item->columnKey,
                    code: 'invalid_custom_field_number',
                    message: "Custom field {$item->customFieldId} expects number."
                );
            }
        }

        return $errors;
    }
}
```

### 7.4.1 Row validator co context (workspace/tenant)

Neu ban can validate theo `workspace_id` hoac `tenant_id`, implement:
- `ContextAwareRowValidatorInterface`

```php
use Vendor\ImportKit\Contracts\ContextAwareRowValidatorInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationResult;

final class PositionRowValidator implements ContextAwareRowValidatorInterface
{
    public function validate(array $normalizedRow): ValidationResult
    {
        // Backward-compatible fallback
        return ValidationResult::ok();
    }

    public function validateWithContext(array $normalizedRow, ImportRunContext $context): ValidationResult
    {
        $workspaceId = $context->workspaceId;
        // Query uniqueness/scoping rules by workspace_id here
        return ValidationResult::ok();
    }
}
```

Behavior:
- Neu validator implement interface tren, `ImportPipeline` se uu tien goi `validateWithContext(...)`.
- Neu khong implement, pipeline van goi `validate(...)` nhu cu (backward-compatible).

### 7.5 Commit có context (tenant/workspace)

Nếu bạn cần context trong commit layer, implement:
- `ContextAwareRowCommitterInterface`

```php
use Vendor\ImportKit\Contracts\ContextAwareRowCommitterInterface;
use Vendor\ImportKit\DTO\ImportRunContext;

final class UserRowCommitter implements ContextAwareRowCommitterInterface
{
    public function commit(array $mappedRow): void
    {
        // fallback behavior
    }

    public function commitWithContext(array $mappedRow, ImportRunContext $context): void
    {
        // Use $context->workspaceId / $context->tenantId
        // Upsert custom field values with idempotent key (entity_id + custom_field_id)
    }
}
```

### 7.6 Custom message cho `InvalidTemplateException`

Nếu bạn muốn đổi message lỗi template theo module (ví dụ `UserImportModule`), implement:
- `TemplateErrorMessageAwareImportModuleInterface`

```php
use Vendor\ImportKit\Contracts\ImportModuleInterface;
use Vendor\ImportKit\Contracts\TemplateErrorMessageAwareImportModuleInterface;

final class UserImportModule implements ImportModuleInterface, TemplateErrorMessageAwareImportModuleInterface
{
    public function invalidTemplateMessage(): string
    {
        return 'Template import User không hợp lệ. Vui lòng dùng đúng mẫu file.';
    }

    // ... các method khác của ImportModuleInterface
}
```

Behavior:
- Khi strict template fail, pipeline sẽ throw `InvalidTemplateException`.
- Nếu module có implement interface trên, exception message sẽ lấy từ `invalidTemplateMessage()`.
- Nếu không implement, message mặc định vẫn là `Import template is invalid.`.

---

## 8) Đăng ký module vào registry / Register module

Bạn đăng ký module trong app service provider:

```php
use Vendor\ImportKit\Contracts\ImportRegistryInterface;

public function boot(): void
{
    $registry = app(ImportRegistryInterface::class);
    $registry->register(app(UserImportModule::class));
}
```

---

## 9) Preview flow implementation (chi tiết)

### 9.1 Tạo `StoredFile`

```php
use Vendor\ImportKit\DTO\StoredFile;

$file = new StoredFile(
    handle: 'import-kit/tmp/abc.xlsx',
    disk: 'local',
    path: 'import-kit/tmp/abc.xlsx',
    meta: [
        'tenant_id' => 10,
        'workspace_id' => 99,
        'context' => ['requested_by' => 123],
    ]
);
```

### 9.2 Gọi preview service

```php
use Vendor\ImportKit\Services\ImportPreviewService;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\Support\RowWindow;

$service = app(ImportPreviewService::class);

$result = $service->preview(
    kind: 'user_import',
    sessionId: $sessionId,
    file: $file,
    runContext: ImportRunContext::from(tenantId: 10, workspaceId: 99, context: []),
    rowWindow: RowWindow::fromPage(1, 20)
);
```

`$result` có:
- `summary`
- `rows` (`ok`/`error`)
- `column_labels`
- `pagination`

Nếu template sai strict rule:
- throw `InvalidTemplateException`
- có error codes chi tiết (`missing_required_header`, `invalid_header_position`, `invalid_custom_header_format`, ...).
- có thể custom message exception bằng `TemplateErrorMessageAwareImportModuleInterface`.

---

## 10) Commit flow implementation (chi tiết)

Lưu ý architecture (multi-container):
- Preview phase: ưu tiên `import.files.disk=local` để đọc nhanh.
- Submit phase: package sẽ ensure file nằm trên `import.submit.disk` (default `s3_happytime`) trước khi queue job.
- Worker phase: file được tải về local temp (`import.worker.local_temp_dir`) để parser đọc, xong sẽ cleanup temp + source submit, và mark session `consumed`.

### 10.1 Submit commit job

```php
use Vendor\ImportKit\Services\ImportCommitService;
use Vendor\ImportKit\DTO\ImportRunContext;

$service = app(ImportCommitService::class);

$job = $service->submit(
    kind: 'user_import',
    sessionId: $sessionId,
    runContext: ImportRunContext::from(tenantId: 10, workspaceId: 99, context: []),
    submittedBy: auth()->id()
);
```

### 10.1.1 Chon dispatch mode: single hoac Bus::batch

Mac dinh package van giu behavior cu:
- `single`: 1 `RunImportJob` xu ly toan bo file.

Neu muon chia theo chunk qua Laravel Bus batch:

```dotenv
IMPORT_COMMIT_DISPATCH_MODE=bus_batch
IMPORT_COMMIT_BATCH_CHUNK_SIZE=500
IMPORT_COMMIT_BATCH_ALLOW_FAILURES=false
```

Ghi chu:
- `single` va `bus_batch` deu append vao cung `import_job_result_rows` + `import_job_errors`, khong thay doi API doc ket qua.
- `bus_batch` dung `incrementProgress` theo chunk de cong don atomically, tranh mat du lieu progress khi job chay song song.
- Sau khi tat ca chunk thanh cong, package queue them `FinalizeImportJob` de mark `completed`, cap nhat summary cuoi va `consumed` session.
- Module co the override theo `kind`/`workspace` bang interface `CommitDispatchAwareImportModuleInterface`.
- Neu module khong override thi package dung config global `import.commit.*`.

### 10.2 Poll status

```php
use Vendor\ImportKit\Services\ImportJobStatusService;

$statusService = app(ImportJobStatusService::class);
$jobState = $statusService->get($job->id);
```

### 10.3 Read result rows/errors

```php
use Vendor\ImportKit\Services\ImportResultService;
use Vendor\ImportKit\Support\RowWindow;

$resultService = app(ImportResultService::class);

$rows = $resultService->resultRows($job->id, 'error', RowWindow::fromPage(1, 50));
```

---

## 11) CSV export result

```php
use Vendor\ImportKit\Services\ImportResultExportService;

$exporter = app(ImportResultExportService::class);
$csvError = $exporter->exportCsvByStatus($jobId, 'error');
$csvAll = $exporter->exportCsvByStatus($jobId, 'all');
```

---

## 12) MySQL vs Mongo

### MySQL (default)

`.env`:

```dotenv
IMPORT_STORAGE_DRIVER=mysql
```

### Mongo

Install:

```bash
composer require mongodb/laravel-mongodb
```

`.env`:

```dotenv
IMPORT_STORAGE_DRIVER=mongo
IMPORT_MONGO_CONNECTION=mongodb
```

---

## 13) Error codes reference (template level)

Thường gặp:
- `missing_required_header`
- `invalid_header_position`
- `invalid_custom_header_format`
- `custom_field_not_active`

Row-level (business/custom datatype) do module bạn define qua `ValidationError.code`.

---

## 14) Best practices / Kinh nghiệm production

- **Code-first policy**: Đặt header policy trong module class, tránh config phình to.
- **Idempotent commit**: Upsert theo `(entity_id, custom_field_id)`.
- **Separation**: Parse/Validate/Map/Commit tách nhỏ, để test.
- **Strict templates** cho flow bắt buộc format cố định.
- **Flexible headers** (chỉ `requiredHeaders`) cho flow cho phép đổi thứ tự cột.
- **Queue monitoring**: Đặt alert cho job `failed`.
- **Audit trail**: Lưu payload gốc + mapped payload để debug nhanh.

---

## 15) FAQ

### Q1: Có cần `requiredHeaders` nếu đã `strictCoreColumns`?

Không bắt buộc.
- Strict mode đã check exact theo vị trí.
- `requiredHeaders` là lớp bảo vệ bổ sung khi muốn check theo key.

### Q2: Header tiếng Việt có dấu có được không?

Có.
- `strictCoreColumns` compare exact string.
- Cần đảm bảo text trong file khớp 100%.

### Q3: Tôi không muốn config `kinds` trong `import.php`?

Đúng.
- Package hiện tại ưu tiên policy trong module class.
- `config.header.kinds` chỉ là fallback backward-compatible.

### Q4: Custom field lấy từ đâu?

2 cách:
- Implement `CustomFieldCatalogAwareImportModuleInterface` trong module (recommended).
- Hoặc bind shared `CustomFieldCatalogInterface`.

### Q5: Tôi muốn đổi message khi template sai?

Implement `TemplateErrorMessageAwareImportModuleInterface` trong module và trả về message qua `invalidTemplateMessage()`.
Nếu không implement interface này, package sẽ dùng message mặc định `Import template is invalid.`.

---

## 16) Sample references in package

- Module sample: `src/Modules/Samples/UserImportModuleExample.php`
- Header policy helper: `src/Modules/Concerns/HasHeaderPolicy.php`
- Pipeline core: `src/Pipeline/ImportPipeline.php`
- Resolver: `src/Infrastructure/Readers/SourceReaderResolver.php`
- Locator: `src/Infrastructure/Readers/ConfigurableHeaderLocator.php`

---

## 17) Minimal rollout checklist

- [ ] Register module vào registry.
- [ ] Implement parser/validator/mapper/committer.
- [ ] Implement header policy in module.
- [ ] Implement dynamic custom field source from DB.
- [ ] Add preview endpoint + session creation.
- [ ] Add commit endpoint + status polling endpoint.
- [ ] Add result list/export endpoint.
- [ ] Add tests cho template errors + row validation + commit idempotency.

---

## 18) Final note

Nếu bạn đang migrate từ legacy import controller:
- Làm preview endpoint trước.
- Sau đó move commit logic vào `RowCommitterInterface`.
- Cuối cùng mở strict template policy để khóa chặt format file.
