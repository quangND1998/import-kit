<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Vendor\ImportKit\Contracts\ImportJobRepositoryInterface;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;

final class FinalizeImportJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $jobId,
        public readonly bool $cleanupSourceOnSuccess = true
    ) {
    }

    public function handle(
        ImportJobRepositoryInterface $jobs,
        PreviewSessionStoreInterface $sessions
    ): void {
        $job = $jobs->find($this->jobId);
        if ($job === null) {
            return;
        }

        if ($job->status === 'failed') {
            $jobs->update($this->jobId, [
                'finished_at' => CarbonImmutable::now(),
            ]);

            return;
        }

        $session = $sessions->find($job->sessionId);
        if ($session === null) {
            $jobs->update($this->jobId, [
                'status' => 'failed',
                'summary' => ['message' => "Import preview session '{$job->sessionId}' not found during finalize."],
                'finished_at' => CarbonImmutable::now(),
            ]);

            return;
        }

        $summary = [
            'total_seen' => $job->totalRows,
            'ok' => $job->okRows,
            'error' => $job->errorRows,
            'skipped_blank' => $job->skippedBlankRows,
        ];

        $jobs->update($this->jobId, [
            'status' => 'completed',
            'summary' => $summary,
            'finished_at' => CarbonImmutable::now(),
        ]);

        if ($this->cleanupSourceOnSuccess) {
            $sessionContext = $session->context;
            $sessionDisk = is_array($sessionContext) && isset($sessionContext['disk']) && is_string($sessionContext['disk'])
                ? $sessionContext['disk']
                : (string) Config::get('import.files.disk', 'local');

            $this->cleanupSourceFile($sessionDisk, $session->fileHandle);
        }

        $sessions->updateStatus($job->sessionId, 'consumed');
    }

    private function cleanupSourceFile(string $disk, string $fileHandle): void
    {
        try {
            Storage::disk($disk)->delete($fileHandle);
        } catch (Throwable) {
            // Best-effort cleanup. Import result has already been committed.
        }
    }
}

