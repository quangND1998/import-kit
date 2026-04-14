<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use Vendor\ImportKit\Contracts\HeaderLocatorInterface;
use Vendor\ImportKit\Contracts\HeaderLocatorRegistryInterface;

final class HeaderLocatorRegistry implements HeaderLocatorRegistryInterface
{
    /**
     * @var array<string, HeaderLocatorInterface>
     */
    private array $locators = [];

    public function __construct(
        private readonly HeaderLocatorInterface $defaultLocator
    ) {
    }

    public function register(string $kind, HeaderLocatorInterface $locator): void
    {
        $this->locators[$kind] = $locator;
    }

    public function resolve(?string $kind = null): HeaderLocatorInterface
    {
        if ($kind !== null && isset($this->locators[$kind])) {
            return $this->locators[$kind];
        }

        return $this->defaultLocator;
    }
}
