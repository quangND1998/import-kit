<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

interface ImportRegistryInterface
{
    public function get(string $kind): ImportModuleInterface;

    /**
     * @return array<int, string>
     */
    public function kinds(): array;
}
