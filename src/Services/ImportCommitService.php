<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Services;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\DTO\ImportJobData;
use Vendor\ImportKit\Jobs\RunImportJob;

final class ImportCommitService
{
    public function __construct(
        private readonly ImportJobRepositoryInterface $jobs
    ) {
    }

    public function submit(
        string $kind,
        string $sessionId,
        ?int $submittedBy = null,
        ?int $tenantId = null,
        ?int $workspaceId = null
    ): ImportJobData {
        $job = new ImportJobData(
            id: (string) Str::uuid(),
            kind: $kind,
            sessionId: $sessionId,
            status: 'pending',
            submittedBy: $submittedBy,
            tenantId: $tenantId,
            workspaceId: $workspaceId
        );

        $job = $this->jobs->create($job);

        Queue::push(new RunImportJob($job->id));

        return $job;
    }
}
