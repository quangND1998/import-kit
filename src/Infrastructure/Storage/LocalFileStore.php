<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Infrastructure\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Vendor\ImportKit\Contracts\FileStoreInterface;
use Vendor\ImportKit\DTO\StoredFile;

final class LocalFileStore implements FileStoreInterface
{
    public function putUploadedFile(UploadedFile $file, array $meta = []): StoredFile
    {
        $disk = (string) config('import.files.disk', 'local');
        $directory = trim((string) config('import.files.directory', 'import-kit'), '/');
        $fileName = (string) Str::uuid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs($directory, $fileName, $disk);
        $handle = (string) $path;

        return new StoredFile($handle, $disk, $handle, $meta);
    }

    public function exists(string $fileHandle): bool
    {
        $disk = (string) config('import.files.disk', 'local');
        return Storage::disk($disk)->exists($fileHandle);
    }

    public function openStream(string $fileHandle)
    {
        $disk = (string) config('import.files.disk', 'local');
        $stream = Storage::disk($disk)->readStream($fileHandle);
        if ($stream === false) {
            throw new RuntimeException("Unable to open stream for file handle {$fileHandle}");
        }

        return $stream;
    }

    public function delete(string $fileHandle): void
    {
        $disk = (string) config('import.files.disk', 'local');
        Storage::disk($disk)->delete($fileHandle);
    }
}
