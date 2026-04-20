<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Console;

use Illuminate\Console\Command;
use Vendor\ImportKit\Contracts\PreviewSessionStoreInterface;

final class PruneExpiredImportPreviewSessionsCommand extends Command
{
    protected $signature = 'import-kit:prune-expired-preview-sessions {--dry-run : Only print how many would be deleted}';

    protected $description = 'Delete expired import preview sessions and their snapshot rows (TTL cleanup).';

    public function handle(PreviewSessionStoreInterface $sessions): int
    {
        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run: call without --dry-run to delete expired sessions.');

            return self::SUCCESS;
        }

        $deleted = $sessions->deleteExpiredPreviewSessions();
        $this->info("Deleted {$deleted} expired preview session(s).");

        return self::SUCCESS;
    }
}
