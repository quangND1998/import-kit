<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

interface RowParserInterface
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function parse(array $row): array;
}
