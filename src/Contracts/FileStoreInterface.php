<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Illuminate\Http\UploadedFile;
use Vendor\ImportKit\DTO\StoredFile;

interface FileStoreInterface
{
    /**
     * @param array<string, mixed> $meta
     */
    public function putUploadedFile(UploadedFile $file, array $meta = []): StoredFile;

    public function exists(string $fileHandle): bool;

    /**
     * @return resource
     */
    public function openStream(string $fileHandle);

    public function delete(string $fileHandle): void;
}
