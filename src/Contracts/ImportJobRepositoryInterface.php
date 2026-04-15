<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\ImportJobErrorData;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\DTO\ImportJobResultRowData;
use Vendor\ImportKit\Support\RowWindow;

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

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page: int, per_page: int, filtered_total: int, next_cursor: ?string},
     *   filters: array{status: ?string}
     * }
     */
    public function getResultRows(string $id, ?string $status = null, ?RowWindow $rowWindow = null): array;
}
