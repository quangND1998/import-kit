<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

interface ImportModuleInterface
{
    public function kind(): string;

    /**
     * @return array<int, string>
     */
    public function requiredHeaders(): array;

    /**
     * @return array<int, string>
     */
    public function optionalHeaders(): array;

    /**
     * @return array<string, string>
     */
    public function columnLabels(): array;

    public function makeRowParser(): RowParserInterface;

    public function makeRowValidator(): RowValidatorInterface;

    public function makeRowMapper(): RowMapperInterface;

    public function makeRowCommitter(): RowCommitterInterface;
}
