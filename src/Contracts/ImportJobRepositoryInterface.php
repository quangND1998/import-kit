<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportJobErrorData;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\DTO\ImportJobResultRowData;

interface ImportJobRepositoryInterface
{
    public function create(ImportJobData $job): ImportJobData;

    public function find(string $id): ?ImportJobData;

    /**
     * @param array<string, mixed> $payload
     */
    public function update(string $id, array $payload): void;

    /**
     * @param array<string, int> $progress
     */
    public function updateProgress(string $id, array $progress): void;

    /**
     * @param array<int, ImportJobResultRowData> $rows
     */
    public function appendRows(string $id, array $rows): void;

    /**
     * @param array<int, ImportJobErrorData> $errors
     */
    public function appendErrors(string $id, array $errors): void;
}
