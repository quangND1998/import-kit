<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Readers;

use InvalidArgumentException;
use Vendor\ImportKit\Contracts\HeaderLocatorRegistryInterface;
use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\Contracts\SourceReaderResolverInterface;
use Vendor\ImportKit\DTO\StoredFile;

final class SourceReaderResolver implements SourceReaderResolverInterface
{
    public function __construct(
        private readonly HeaderLocatorRegistryInterface $headerLocatorRegistry
    ) {
    }

    public function resolve(StoredFile $file, ?string $kind = null): SourceReaderInterface
    {
        $extension = strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return new CsvSourceReader();
        }

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return new SpreadsheetSourceReader($this->headerLocatorRegistry->resolve($kind));
        }

        throw new InvalidArgumentException("Unsupported import file extension '{$extension}'.");
    }
}
