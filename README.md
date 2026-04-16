# Import Kit

Reusable Laravel import package with preview + async commit pipeline.

Goi y ngon ngu / Language note:
- Tai lieu viet theo kieu song ngu ngan gon (Viet + English keywords).
- Code examples uu tien tieng Anh de copy/paste.

---

## 1) Muc tieu package / What this package solves

Package nay giup ban xay import pipeline theo pattern:
- Upload file -> Preview validation result.
- Confirm import -> Queue async commit.
- Track status + errors + result rows.
- Ho tro custom field dong theo workspace/tenant.
- Ho tro strict template mapping (header row, column order, custom header format).

Phu hop khi ban muon:
- Tach business rule ra khoi controller lon.
- Dung chung import infra cho nhieu domain (`employee`, `user`, `cost_center`, ...).
- Co flow polling ket qua import job.

---

## 2) Kien truc tong quan / High-level architecture

Core components:
- `ImportModuleInterface`: module business cho tung `kind`.
- `ImportPipeline`: parser -> validator -> mapper -> committer.
- `ImportPreviewService`: chay preview mode.
- `ImportCommitService`: tao job async commit.
- `RunImportJob`: worker consume queue, chay commit mode.
- `SourceReaderResolver`: chon `CsvSourceReader` hoac `SpreadsheetSourceReader`.
- `ConfigurableHeaderLocator`: strict header/custom field validation metadata.

Data stores:
- MySQL hoac Mongo cho:
  - preview sessions
  - import jobs
  - import errors
  - import result rows

---

## 3) Requirements

- PHP `>=8.0`
- Laravel `>=10.0`
- `phpoffice/phpspreadsheet` cho `xlsx/xls`

---

## 4) Installation

```bash
composer require vendor/import-kit
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

## 5) Quick start (5 buoc) / Quick start in 5 steps

1. Tao module class implement `ImportModuleInterface`.
2. Dang ky module vao `ImportRegistryInterface`.
3. Goi preview service de validate file.
4. Tao preview session trong app layer (neu app ban dang quan ly session id).
5. Submit commit job va poll status.

Chi tiet o cac muc ben duoi.

---

## 6) Cau hinh package / Package config

File: `config/import.php`

Cac key quan trong:
- `storage_driver`: `mysql` | `mongo`
- `database.*`: table/collection names
- `files.disk`, `files.directory`
- `preview.expires_minutes`, `preview.default_per_page`
- `column_labels`
- `header.default` (fallback policy, khong phai noi uu tien)

### Header config philosophy

Ban khong can khai bao policy theo `kinds` trong config.

Recommended:
- Define header policy trong module class (code-first).
- `config.import.header.default` chi la fallback de backward-compatible.

---

## 7) Huong dan implement module chi tiet / Detailed module implementation

### 7.1 Tao module co ban

```php
use Vendor\ImportKit\Contracts\ImportModuleInterface;
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
            'employee_id' => 'Ma dinh danh',
            'full_name' => 'Ho va ten',
        ];
    }

    public function makeRowParser(): RowParserInterface { /* ... */ }
    public function makeRowValidator(): RowValidatorInterface { /* ... */ }
    public function makeRowMapper(): RowMapperInterface { /* ... */ }
    public function makeRowCommitter(): RowCommitterInterface { /* ... */ }
}
```

### 7.2 Strict header policy trong module (recommended)

Implement them interface:
- `HeaderPolicyAwareImportModuleInterface`

```php
use Vendor\ImportKit\Contracts\HeaderPolicyAwareImportModuleInterface;
use Vendor\ImportKit\DTO\HeaderPolicy;
use Vendor\ImportKit\DTO\ImportRunContext;

final class UserImportModule implements ImportModuleInterface, HeaderPolicyAwareImportModuleInterface
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
}
```

Luu y quan trong:
- `strictCoreColumns` compare exact string (`===`), nen tieng Viet co dau duoc ho tro.
- Neu file sai dau/space/* -> invalid template.

### 7.3 Dynamic custom fields tu DB trong module

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
        // Vi du query DB theo workspace:
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
            new CustomFieldDefinition(id: '123', title: 'Thu nhap', dataType: 'NUMBER'),
            new CustomFieldDefinition(id: '124', title: 'Ngay vao cong ty', dataType: 'DATE'),
        ];
    }
}
```

### 7.4 Validate custom field values theo datatype

Implement:
- `CustomFieldAwareImportModuleInterface`

Pipeline se truyen custom values da parse vao module de validate row-level.

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

### 7.5 Commit co context (tenant/workspace)

Neu ban can context trong commit layer, implement:
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

---

## 8) Dang ky module vao registry / Register module

Ban dang ky module trong app service provider:

```php
use Vendor\ImportKit\Contracts\ImportRegistryInterface;

public function boot(): void
{
    $registry = app(ImportRegistryInterface::class);
    $registry->register(app(UserImportModule::class));
}
```

---

## 9) Preview flow implementation (chi tiet)

### 9.1 Tao `StoredFile`

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

### 9.2 Goi preview service

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

`$result` co:
- `summary`
- `rows` (`ok`/`error`)
- `column_labels`
- `pagination`

Neu template sai strict rule:
- throw `InvalidTemplateException`
- co error codes chi tiet (`missing_required_header`, `invalid_header_position`, `invalid_custom_header_format`, ...).

---

## 10) Commit flow implementation (chi tiet)

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

Thuong gap:
- `missing_required_header`
- `invalid_header_position`
- `invalid_custom_header_format`
- `custom_field_not_active`

Row-level (business/custom datatype) do module ban define qua `ValidationError.code`.

---

## 14) Best practices / Kinh nghiem production

- **Code-first policy**: Dat header policy trong module class, tranh config phinh to.
- **Idempotent commit**: Upsert theo `(entity_id, custom_field_id)`.
- **Separation**: Parse/Validate/Map/Commit tach nho, de test.
- **Strict templates** cho flow bat buoc format co dinh.
- **Flexible headers** (chi `requiredHeaders`) cho flow cho phep doi thu tu cot.
- **Queue monitoring**: Dat alert cho job `failed`.
- **Audit trail**: Luu payload goc + mapped payload de debug nhanh.

---

## 15) FAQ

### Q1: Co can `requiredHeaders` neu da `strictCoreColumns`?

Khong bat buoc.
- Strict mode da check exact theo vi tri.
- `requiredHeaders` la lop bao ve bo sung khi muon check theo key.

### Q2: Header tieng Viet co dau co duoc khong?

Co.
- `strictCoreColumns` compare exact string.
- Can dam bao text trong file khop 100%.

### Q3: Toi khong muon config `kinds` trong `import.php`?

Dung.
- Package hien tai uu tien policy trong module class.
- `config.header.kinds` chi la fallback backward-compatible.

### Q4: Custom field lay tu dau?

2 cach:
- Implement `CustomFieldCatalogAwareImportModuleInterface` trong module (recommended).
- Hoac bind shared `CustomFieldCatalogInterface`.

---

## 16) Sample references in package

- Module sample: `src/Modules/Samples/UserImportModuleExample.php`
- Header policy helper: `src/Modules/Concerns/HasHeaderPolicy.php`
- Pipeline core: `src/Pipeline/ImportPipeline.php`
- Resolver: `src/Infrastructure/Readers/SourceReaderResolver.php`
- Locator: `src/Infrastructure/Readers/ConfigurableHeaderLocator.php`

---

## 17) Minimal rollout checklist

- [ ] Register module vao registry.
- [ ] Implement parser/validator/mapper/committer.
- [ ] Implement header policy in module.
- [ ] Implement dynamic custom field source from DB.
- [ ] Add preview endpoint + session creation.
- [ ] Add commit endpoint + status polling endpoint.
- [ ] Add result list/export endpoint.
- [ ] Add tests cho template errors + row validation + commit idempotency.

---

## 18) Final note

Neu ban dang migrate tu legacy import controller:
- Lam preview endpoint truoc.
- Sau do move commit logic vao `RowCommitterInterface`.
- Cuoi cung mo strict template policy de khoa chat format file.
