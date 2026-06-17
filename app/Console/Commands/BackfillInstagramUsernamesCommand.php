<?php

namespace App\Console\Commands;

use App\Http\Controllers\InstagramIntegrationController;
use App\Models\InstagramIntegration;
use App\Models\Lead;
use Illuminate\Console\Command;

class BackfillInstagramUsernamesCommand extends Command
{
    protected $signature = 'instagram:backfill-usernames';

    protected $description = 'Fetch and store the Instagram username for existing leads that only have an IGSID';

    public function handle(InstagramIntegrationController $controller): int
    {
        $leads = Lead::where('source_platform', 'instagram')
            ->whereNull('username')
            ->whereNotNull('external_id')
            ->get();

        if ($leads->isEmpty()) {
            $this->info('No Instagram leads are missing a username.');
            return self::SUCCESS;
        }

        $this->info("Found {$leads->count()} Instagram lead(s) missing a username.");

        $integrationsByCompany = InstagramIntegration::whereIn('company_id', $leads->pluck('company_id')->unique())
            ->get()
            ->keyBy('company_id');

        $updated = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            $integration = $integrationsByCompany->get($lead->company_id);

            if (!$integration || !$integration->access_token) {
                $skipped++;
                continue;
            }

            $profile = $controller->fetchInstagramProfile($integration, $lead->external_id);

            if (empty($profile['username'])) {
                $skipped++;
                continue;
            }

            $lead->update(['username' => $profile['username']]);
            $updated++;
        }

        $this->info("Updated {$updated} lead(s), skipped {$skipped} (no integration/access token or no username available).");

        return self::SUCCESS;
    }
}
