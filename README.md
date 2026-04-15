# Import Kit

Reusable Laravel import package with a shared pipeline for preview and async commit.

## Requirements

- PHP `>=8.0`
- Laravel `>=10.0`

## Installation

```bash
composer require vendor/import-kit
```

## Publish package files

Publish config (`config/import.php`):

```bash
php artisan vendor:publish --provider="Vendor\\ImportKit\\ImportKitServiceProvider" --tag=import-kit-config
```

Publish migrations:

```bash
php artisan vendor:publish --provider="Vendor\\ImportKit\\ImportKitServiceProvider" --tag=import-kit-migrations
```

Run migrations:

```bash
php artisan migrate
```

Refresh config cache:

```bash
php artisan config:clear
php artisan config:cache
```

## Mongo support

Install MongoDB Laravel driver in consumer app:

```bash
composer require mongodb/laravel-mongodb
```

Then switch storage driver in `.env`:

```dotenv
IMPORT_STORAGE_DRIVER=mongo
IMPORT_MONGO_CONNECTION=mongodb
```

## Spreadsheet support

Install spreadsheet dependency in consumer app:

```bash
composer require phpoffice/phpspreadsheet
```

The package now supports `csv`, `xlsx`, and `xls` via a source reader resolver.
Override `Vendor\ImportKit\Contracts\HeaderLocatorInterface` in your app container to customize
header detection for non-fixed spreadsheet formats.

For multi-module apps, register per-kind header locators using
`Vendor\ImportKit\Contracts\HeaderLocatorRegistryInterface`:

```php
use Vendor\ImportKit\Contracts\HeaderLocatorRegistryInterface;

app(HeaderLocatorRegistryInterface::class)->register('cost_center_entry', app(PayrollHeaderLocator::class));
app(HeaderLocatorRegistryInterface::class)->register('employee', app(EmployeeHeaderLocator::class));
```

## Config highlights (`config/import.php`)

- `storage_driver`: `mysql` or `mongo`.
- `files.disk`: Laravel filesystem disk for import file storage (`local`, `s3`, ...).
- `history.enabled`: toggle persisting import history records.
- `workspace_id_nullable`: keep workspace optional for multi-workspace modules.
- `column_labels`: FE-friendly per-kind label map.

Example:

```php
'column_labels' => [
    'default' => [
        'costcenter_code' => 'Cost center code',
        'amount' => 'Amount',
    ],
    'cost_center_entry' => [
        'category_code' => 'Category',
    ],
],
```

S3 example:

```dotenv
IMPORT_FILES_DISK=s3
IMPORT_FILES_DIRECTORY=import-kit
```

## Runtime commands

Use queue worker for background commit jobs:

```bash
php artisan queue:work
```

## Preview pagination

Use `RowWindow::fromPage($page, $perPage)` when calling preview service.

Preview response pagination format:

```json
{
  "pagination": {
    "page": 1,
    "per_page": 20,
    "filtered_total": 20,
    "next_cursor": "20"
  }
}
```

## Filter preview/result by status

Use `ImportResultService`:

```php
use Vendor\ImportKit\Services\ImportResultService;
use Vendor\ImportKit\Support\RowWindow;

$service = app(ImportResultService::class);

// Preview snapshot rows filtered by status
$preview = $service->previewRows($sessionId, 'error', RowWindow::fromPage(1, 20));

// Commit result rows filtered by status
$result = $service->resultRows($jobId, 'ok', RowWindow::fromPage(1, 50));
```

Both responses include:

- `rows`
- `pagination` (`page`, `per_page`, `filtered_total`, `next_cursor`)
- `filters` (`status`)

## Export result by status (CSV)

Use `ImportResultExportService`:

```php
use Vendor\ImportKit\Services\ImportResultExportService;

$exporter = app(ImportResultExportService::class);
$csv = $exporter->exportCsvByStatus($jobId, 'error');
```

## Notes

- `workspace_id` is nullable in preview session/job records.
- Preview and commit use the same parser/validator/mapper pipeline.
- Column labels are returned via `column_labels` in preview payload.
