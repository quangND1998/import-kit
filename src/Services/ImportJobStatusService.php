<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\DTO\ImportJobData;

final class ImportJobStatusService
{
    public function __construct(
        private readonly ImportJobRepositoryInterface $jobs
    ) {
    }

    public function get(string $jobId): ?ImportJobData
    {
        return $this->jobs->find($jobId);
    }
}
