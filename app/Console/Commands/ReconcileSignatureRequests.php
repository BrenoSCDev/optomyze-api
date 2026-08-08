<?php

namespace App\Console\Commands;

use App\Models\SignatureRequest;
use App\Services\Signatures\Exceptions\SignatureProviderException;
use App\Services\Signatures\SignatureProviderRegistry;
use App\Services\Signatures\Support\SignatureStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for webhooks that never arrived.
 *
 * Webhooks are the design — this is what covers the days the endpoint was
 * down, the vendor had an outage, or a callback got deregistered. It asks the
 * vendor directly about requests that have been open a while and corrects any
 * that have moved on without us.
 *
 * Deliberately conservative: it only ever moves a request *forward* into a
 * terminal state, never back, so a stale read cannot undo something a webhook
 * already told us.
 *
 * Schedule it hourly.
 */
class ReconcileSignatureRequests extends Command
{
    protected $signature = 'signatures:reconcile
                            {--hours=1 : Only check requests untouched for at least this many hours}
                            {--limit=200 : Maximum requests to check in one run}';

    protected $description = 'Reconcile open signature requests against their provider, for events that never arrived';

    public function handle(SignatureProviderRegistry $registry): int
    {
        $requests = SignatureRequest::with('providerAccount')
            ->whereIn('status', SignatureStatus::OPEN)
            ->whereNotNull('provider_request_id')
            ->where('updated_at', '<=', now()->subHours((int) $this->option('hours')))
            ->limit((int) $this->option('limit'))
            ->get();

        if ($requests->isEmpty()) {
            $this->info('No open signature requests need reconciling.');

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ($requests as $request) {
            if (!$request->providerAccount?->isUsable()) {
                continue;
            }

            try {
                $state = $registry->get($request->provider)->fetchStatus($request);

                if ($state->status === $request->status) {
                    // Touch it so the next run looks at older ones first.
                    $request->touch();
                    continue;
                }

                // Forward-only: never walk a request back out of a terminal
                // state on the strength of a poll.
                if (!SignatureStatus::isTerminal($state->status)) {
                    continue;
                }

                $request->forceFill(array_filter([
                    'status'       => $state->status,
                    'completed_at' => $state->status === SignatureStatus::COMPLETED
                        ? ($state->completedAt ?? now())
                        : null,
                    'canceled_at'  => $state->status === SignatureStatus::CANCELED ? now() : null,
                ], fn ($value) => $value !== null))->save();

                $updated++;

                $this->line("Request #{$request->id} reconciled to [{$state->status}].");
            } catch (SignatureProviderException $e) {
                Log::warning('Signature reconciliation failed for one request', [
                    'signature_request_id' => $request->id,
                    'provider'             => $request->provider,
                    'error'                => $e->getMessage(),
                ]);
            }
        }

        $this->info("Checked {$requests->count()} request(s), updated {$updated}.");

        return self::SUCCESS;
    }
}
