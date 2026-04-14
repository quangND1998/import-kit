<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

interface HeaderLocatorInterface
{
    /**
     * @return array{header_row:int,header_map:array<string,int>}
     */
    public function locate(Worksheet $sheet, int $highestRow, int $highestColumnIndex): array;
}
