<?php

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use Illuminate\Console\Command;

class PruneApiRequestLogsCommand extends Command
{
    protected $signature = 'api-logs:prune {--days=30 : Delete logs older than this many days}';

    protected $description = 'Delete API request logs older than the given retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        // Delete in batches so a large backlog doesn't hold one long-running
        // lock against the table other API requests are actively writing to.
        $deleted = 0;
        do {
            $count = ApiRequestLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $count;
        } while ($count > 0);

        $this->info("Deleted {$deleted} API request log(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
