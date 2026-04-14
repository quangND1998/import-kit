<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use InvalidArgumentException;
use Vendor\ImportKit\Contracts\ImportModuleInterface;
use Vendor\ImportKit\Contracts\ImportRegistryInterface;

final class ImportRegistry implements ImportRegistryInterface
{
    /**
     * @var array<string, ImportModuleInterface>
     */
    private array $modules = [];

    /**
     * @param iterable<int, ImportModuleInterface> $modules
     */
    public function __construct(iterable $modules = [])
    {
        foreach ($modules as $module) {
            $this->register($module);
        }
    }

    public function register(ImportModuleInterface $module): void
    {
        $this->modules[$module->kind()] = $module;
    }

    public function get(string $kind): ImportModuleInterface
    {
        if (!isset($this->modules[$kind])) {
            throw new InvalidArgumentException("Import module '{$kind}' is not registered.");
        }

        return $this->modules[$kind];
    }

    public function kinds(): array
    {
        return array_keys($this->modules);
    }
}
