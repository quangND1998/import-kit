<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Fixtures;

use Vendor\ImportKit\Contracts\SourceReaderInterface;
use Vendor\ImportKit\DTO\StoredFile;
use Vendor\ImportKit\DTO\TemplateValidationResult;
use Vendor\ImportKit\Support\RowWindow;

final class FakeSourceReader implements SourceReaderInterface
{
    /**
     * @param array<int, string> $headers
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly array $headers,
        private readonly array $rows,
        private readonly array $metadata = [],
        private readonly ?TemplateValidationResult $templateValidation = null
    ) {
    }

    public function open(StoredFile $file): void
    {
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function templateValidation(): TemplateValidationResult
    {
        return $this->templateValidation ?? TemplateValidationResult::ok($this->metadata);
    }

    public function rows(?RowWindow $window = null): iterable
    {
        if ($window === null) {
            yield from $this->rows;

            return;
        }

        $slice = array_slice($this->rows, $window->offset, $window->limit);
        yield from $slice;
    }

    public function close(): void
    {
    }
}

