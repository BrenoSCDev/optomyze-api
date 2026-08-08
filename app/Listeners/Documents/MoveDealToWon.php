<?php

namespace App\Listeners\Documents;

use App\Events\DocumentSigned;
use App\Models\Record;
use App\Models\Stage;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * A signed contract closes the opportunity that produced it.
 *
 * This is the one automation that is safe to ship as first-party behaviour:
 * the deal is *why* the contract exists, and a signed agreement is the
 * definition of a won deal. Anything more opinionated — raising an invoice,
 * creating a project — belongs in the workflow engine, where a company can
 * decide whether it wants it.
 *
 * Deliberately conservative: it only touches deals that are still open. A deal
 * already marked lost, or already closed, is left exactly as the human left
 * it. A second contract signed against the same deal must not re-close it.
 */
class MoveDealToWon
{
    public function onSigned(DocumentSigned $event): void
    {
        $document = $event->document;
        $sale     = $document->sale;

        if (!$sale) {
            return;
        }

        // 'closed' is this CRM's word for won; see SaleController::updateStatus.
        if (in_array($sale->status, ['closed', 'lost'], true)) {
            return;
        }

        // Contracts of a type that is not really an agreement — a proposal, a
        // quote — should not close anything.
        if ($document->type && $document->type->category !== \App\Models\DocumentType::CATEGORY_CONTRACT) {
            return;
        }

        try {
            $fromStatus = $sale->status;

            $updates = [
                'status'    => 'closed',
                'closed_at' => now(),
                'lost_at'   => null,
            ];

            // Keep the board honest: a won deal belongs in the won column.
            if ($sale->funnel_id) {
                $wonStage = Stage::where('funnel_id', $sale->funnel_id)
                    ->where('type', 'won')
                    ->first();

                if ($wonStage) {
                    $updates['stage_id'] = $wonStage->id;
                }
            }

            $sale->update($updates);

            // No actor: this was the counterparty signing, not a CRM user.
            Record::createDealStatusChange($sale, $fromStatus, 'closed', null);

            app(NotificationService::class)->dispatch('deal_won', $sale->company_id, [
                'deal_owner_id' => $sale->user_id,
                'lead_owner_id' => $sale->lead?->assigned_to,
                'actor_name'    => $document->number,
                'sale'          => ['id' => $sale->id, 'name' => $sale->name, 'total' => $sale->total],
                'lead'          => [],
            ]);
        } catch (\Throwable $e) {
            // The signature is already legally complete. Failing to move the
            // deal must not look like the signing failed.
            Log::error('Could not move deal to won after contract signature', [
                'document_id' => $document->id,
                'sale_id'     => $sale->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
