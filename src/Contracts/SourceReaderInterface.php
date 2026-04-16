<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\Support\RowWindow;

interface SourceReaderInterface
{
    public function open(StoredFile $file): void;

    /**
     * @return array<int, string>
     */
    public function headers(): array;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;

    public function templateValidation(): TemplateValidationResult;

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(?RowWindow $window = null): iterable;

    public function close(): void;
}
