<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\HeaderPolicy;

interface HeaderPolicyResolverInterface
{
    public function resolve(?string $kind = null): HeaderPolicy;
}

