# Import Kit

Reusable Laravel import package with preview + async commit pipeline.

Gợi ý ngôn ngữ / Language note:
- Tài liệu viết theo kiểu song ngữ ngắn gọn (Việt + English keywords).
- Code examples ưu tiên tiếng Anh để copy/paste.

### Breaking changes (row contracts) / Thay đổi phá vỡ tương thích

- (EN) `RowParserInterface`, `RowValidatorInterface`, `RowMapperInterface`, `RowCommitterInterface` now require **`ImportRunContext` as the last parameter** on every call. The separate `ContextAware*` interfaces were removed — migrate by merging `*WithContext` logic into the single method.
- (VI) Các interface row bắt buộc thêm tham số **`ImportRunContext`** ở cuối mỗi lần gọi; nhóm `ContextAware*` đã bỏ — hãy gộp logic từ `*WithContext` vào một method duy nhất.
- (EN) Publish new migration `2026_04_21_000006_add_unique_indexes_import_job_rows_errors` for idempotent `appendRows` / `appendErrors` on queue retry (unique `(job_id, line)` and `(job_id, line, field, code)`; null `line` in errors is stored as `-1` for uniqueness).
- (VI) Chạy migration trên để `appendRows` / `appendErrors` idempotent khi queue retry (unique `(job_id, line)` và `(job_id, line, field, code)`; `line` null trong lỗi được lưu `-1`).
- (EN) Translations: `php artisan vendor:publish --tag=import-kit-lang` (optional) or use default English fallbacks via `ImportKitTranslator`.
- (VI) Bản dịch: `php artisan vendor:publish --tag=import-kit-lang` (tùy chọn) hoặc dùng fallback tiếng Anh qua `ImportKitTranslator`.
- (EN) TTL cleanup: `php artisan import-kit:prune-expired-preview-sessions` (schedule daily in your app).
- (VI) Dọn session preview hết hạn: lệnh trên; nên lên lịch hằng ngày trong ứng dụng của bạn.

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
- `ImportModuleInterface`: module nghiệp vụ theo từng `kind`.
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
use Vendor\ImportKit\Contracts\RowParserInterface;
use Vendor\ImportKit\Contracts\RowValidatorInterface;
use Vendor\ImportKit\Contracts\RowMapperInterface;
use Vendor\ImportKit\Contracts\RowCommitterInterface;
use Vendor\ImportKit\DTO\ImportRunContext;

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

    public function makeRowParser(): RowParserInterface { /* parse(array $row, ImportRunContext $context): array */ }
    public function makeRowValidator(): RowValidatorInterface { /* validate(..., ImportRunContext $context) */ }
    public function makeRowMapper(): RowMapperInterface { /* map(..., ImportRunContext $context) */ }
    public function makeRowCommitter(): RowCommitterInterface { /* commit(..., ImportRunContext $context) */ }
}
```

Row contracts (unified): `parse|validate|map|commit` **always** receive `ImportRunContext` as the last argument. Các interface `ContextAware*` đã gỡ bỏ — chỉ cần một method có `ImportRunContext`.

English: Row parser/validator/mapper/committer methods always take `ImportRunContext`; the old dual `parse` / `parseWithContext` split was removed.

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

### 7.4.1 Row validator / commit với `ImportRunContext`

Implement `RowValidatorInterface::validate(array $normalizedRow, ImportRunContext $context)` (và tương tự `RowCommitterInterface::commit(...)`) — pipeline luôn truyền `context`.

```php
use Vendor\ImportKit\Contracts\RowValidatorInterface;
use Vendor\ImportKit\DTO\ImportRunContext;
use Vendor\ImportKit\DTO\ValidationResult;

final class PositionRowValidator implements RowValidatorInterface
{
    public function validate(array $normalizedRow, ImportRunContext $context): ValidationResult
    {
        $workspaceId = $context->workspaceId;
        // Query uniqueness/scoping rules by workspace_id here
        return ValidationResult::ok();
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

Hành vi:
- Khi template strict không hợp lệ, pipeline sẽ phát sinh `InvalidTemplateException`.
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
- có mã lỗi chi tiết (`missing_required_header`, `invalid_header_position`, `invalid_custom_header_format`, …).
- có thể custom message exception bằng `TemplateErrorMessageAwareImportModuleInterface`.

---

## 10) Commit flow implementation (chi tiết)

Lưu ý architecture (multi-container):
- Preview phase: ưu tiên `import.files.disk=local` để đọc nhanh.
- Submit phase: package đảm bảo file nằm trên `import.submit.disk` (mặc định `s3_happytime`) trước khi xếp hàng job.
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

### 10.1.1 Chọn dispatch mode: `single` hoặc `Bus::batch`

Mặc định package vẫn giữ hành vi cũ:
- `single`: một `RunImportJob` xử lý toàn bộ file.

Nếu muốn chia theo chunk qua Laravel `Bus::batch`:

```dotenv
IMPORT_COMMIT_DISPATCH_MODE=bus_batch
IMPORT_COMMIT_BATCH_CHUNK_SIZE=500
IMPORT_COMMIT_BATCH_ALLOW_FAILURES=false
IMPORT_COMMIT_BATCH_PRECOUNT_LOGICAL_ROWS=true
```

- `IMPORT_COMMIT_BATCH_PRECOUNT_LOGICAL_ROWS=true` (default): trước khi dispatch, package **đếm số dòng non-blank sau parse** để biết `chunk_count` rồi mới `Bus::batch` nhiều `RunImportJob` song song.
- `false`: **không** full-scan file lúc submit — chỉ queue chunk đầu; mỗi chunk xong tự queue chunk tiếp cho đến hết file, rồi `FinalizeImportJob` (submit nhanh hơn với xlsx lớn).

Ghi chú:
- `single` và `bus_batch` đều ghi vào cùng `import_job_result_rows` + `import_job_errors`, không đổi API đọc kết quả.
- `bus_batch` dùng `incrementProgress` theo chunk để cộng dồn (atomic), tránh mất dữ liệu tiến độ khi job chạy song song.
- Sau khi tất cả chunk thành công, package xếp hàng thêm `FinalizeImportJob` để đánh dấu `completed`, cập nhật summary cuối và session `consumed`.
- Module có thể ghi đè theo `kind` / `workspace` bằng interface `CommitDispatchAwareImportModuleInterface`.
- Nếu module không ghi đè thì package dùng config global `import.commit.*`.

### 10.1.2 Preview session TTL

Chạy định kỳ (ví dụ: hằng ngày trong `app/Console/Kernel.php`):

```bash
php artisan import-kit:prune-expired-preview-sessions
```

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

Ở từng dòng (nghiệp vụ / kiểu dữ liệu tùy chỉnh), mã lỗi do chính module của bạn định nghĩa qua `ValidationError.code`.

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
- `requiredHeaders` là lớp bảo vệ bổ sung khi muốn kiểm tra theo key.

### Q2: Header tiếng Việt có dấu có được không?

Có.
- `strictCoreColumns` compare exact string.
- Cần đảm bảo text trong file khớp 100%.

### Q2b: Chuẩn hóa header không dấu (ASCII key)?

Đặt `normalize_mode => 'snake_unaccent'` trong `HeaderPolicy` (hoặc config fallback).
File Excel vẫn có thể ghi tiêu đề tiếng Việt có dấu; key trong `requiredHeaders` / `module.requiredHeaders()` là dạng không dấu + underscore (ví dụ `ma_nhan_vien`).
`strict_core_columns` vẫn đòi text trong ô khớp đúng `expected_label` (nếu bạn truyền label có dấu thì file phải có dấu giống vậy).

### Q3: Tôi không muốn config `kinds` trong `import.php`?

Đúng.
- Package hiện tại ưu tiên policy trong module class.
- `config.header.kinds` chỉ là fallback backward-compatible.

### Q4: Custom field lấy từ đâu?

2 cách:
- Implement `CustomFieldCatalogAwareImportModuleInterface` trong module (recommended).
- Hoặc bind shared `CustomFieldCatalogInterface`.

### Q5: Tôi muốn đổi message khi template sai?

Triển khai `TemplateErrorMessageAwareImportModuleInterface` trong module và trả về message qua `invalidTemplateMessage()`.
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

- [ ] Đăng ký module vào registry.
- [ ] Triển khai parser / validator / mapper / committer.
- [ ] Triển khai header policy trong module.
- [ ] Triển khai nguồn custom field động từ DB.
- [ ] Thêm endpoint preview + tạo session.
- [ ] Thêm endpoint commit + poll trạng thái.
- [ ] Thêm endpoint danh sách / export kết quả.
- [ ] Thêm test cho lỗi template, validation từng dòng và idempotent commit.

---

## 18) Final note

Nếu bạn đang migrate từ legacy import controller:
- Làm preview endpoint trước.
- Sau đó chuyển logic commit vào `RowCommitterInterface`.
- Cuối cùng mở strict template policy để khóa chặt format file.
