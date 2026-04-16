<?php

declare(strict_types=1);

namespace Vendor\ImportKit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Vendor\ImportKit\Contracts\FileStoreInterface;
use Vendor\ImportKit\Contracts\HeaderPolicyResolverInterface;
use Vendor\ImportKit\Contracts\HeaderLocatorInterface;
use Vendor\ImportKit\Contracts\HeaderLocatorRegistryInterface;
use Vendor\ImportKit\Contracts\ImportRegistryInterface;
use Vendor\ImportKit\Contracts\CustomFieldCatalogInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\Infrastructure\CustomField\NullCustomFieldCatalog;
use Vendor\ImportKit\Infrastructure\Readers\ConfigHeaderPolicyResolver;
use Vendor\ImportKit\Infrastructure\Readers\DefaultHeaderLocator;
use Vendor\ImportKit\Infrastructure\Readers\HeaderLocatorRegistry;
use Vendor\ImportKit\Infrastructure\Readers\SourceReaderResolver;
use Vendor\ImportKit\Infrastructure\Storage\LocalFileStore;
use Vendor\ImportKit\Pipeline\ImportPipeline;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;
use Vendor\ImportKit\Repositories\Eloquent\EloquentImportJobRepository;
use Vendor\ImportKit\Repositories\Eloquent\EloquentPreviewSessionRepository;
use Vendor\ImportKit\Repositories\Mongo\MongoImportJobRepository;
use Vendor\ImportKit\Repositories\Mongo\MongoPreviewSessionRepository;
use Vendor\ImportKit\Services\ColumnLabelService;
use Vendor\ImportKit\Services\ImportRegistry;
use Vendor\ImportKit\Services\ImportCommitService;
use Vendor\ImportKit\Services\ImportResultExportService;
use Vendor\ImportKit\Services\ImportResultService;
use Vendor\ImportKit\Services\ImportJobStatusService;
use Vendor\ImportKit\Services\ImportPreviewService;

final class ImportKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/import.php', 'import');
        $this->app->singleton(FileStoreInterface::class, LocalFileStore::class);
        $this->app->bind(HeaderLocatorInterface::class, DefaultHeaderLocator::class);
        $this->app->singleton(DefaultHeaderLocator::class, function (): HeaderLocatorInterface {
            /** @var HeaderLocatorInterface $locator */
            $locator = $this->app->make(HeaderLocatorInterface::class);
            return $locator;
        });
        $this->app->singleton(HeaderLocatorRegistryInterface::class, function (): HeaderLocatorRegistryInterface {
            /** @var HeaderLocatorInterface $defaultLocator */
            $defaultLocator = $this->app->make(DefaultHeaderLocator::class);

            return new HeaderLocatorRegistry($defaultLocator);
        });
        $this->app->singleton(SourceReaderResolverInterface::class, SourceReaderResolver::class);
        $this->app->singleton(HeaderPolicyResolverInterface::class, ConfigHeaderPolicyResolver::class);
        $this->app->singleton(CustomFieldCatalogInterface::class, NullCustomFieldCatalog::class);
        $this->app->singleton(ImportPipeline::class);
        $this->app->singleton(ColumnLabelService::class);
        $this->app->singleton(ImportRegistryInterface::class, function (): ImportRegistryInterface {
            return new ImportRegistry([]);
        });
        $this->app->singleton(ImportPreviewService::class);
        $this->app->singleton(ImportCommitService::class);
        $this->app->singleton(ImportJobStatusService::class);
        $this->app->singleton(ImportResultService::class);
        $this->app->singleton(ImportResultExportService::class);

        $this->app->bind(PreviewSessionStoreInterface::class, function (): PreviewSessionStoreInterface {
            if (config('import.storage_driver') === 'mongo') {
                return new MongoPreviewSessionRepository();
            }

            return new EloquentPreviewSessionRepository();
        });

        $this->app->bind(ImportJobRepositoryInterface::class, function (): ImportJobRepositoryInterface {
            if (config('import.storage_driver') === 'mongo') {
                return new MongoImportJobRepository();
            }

            return new EloquentImportJobRepository();
        });
    }

    public function boot(): void
    {
        if (!function_exists('config_path')) {
            return;
        }

        if (File::isDirectory(__DIR__ . '/../database/migrations')) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        $this->publishes([
            __DIR__ . '/../config/import.php' => config_path('import.php'),
        ], 'import-kit-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'import-kit-migrations');
    }
}
