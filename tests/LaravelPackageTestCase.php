<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vendor\ImportKit\ImportKitServiceProvider;

abstract class LaravelPackageTestCase extends OrchestraTestCase
{
    protected string $sqlitePath;

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ImportKitServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->sqlitePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'import-kit-testbench.sqlite';
        if (is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('import.enable_test_routes', true);
        $app['config']->set('import.storage_driver', 'mysql');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->sqlitePath) && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
    }
}
