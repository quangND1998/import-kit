<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

interface HeaderLocatorRegistryInterface
{
    public function register(string $kind, HeaderLocatorInterface $locator): void;

    public function resolve(?string $kind = null): HeaderLocatorInterface;
}
