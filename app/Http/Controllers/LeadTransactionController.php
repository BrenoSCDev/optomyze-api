<?php

namespace App\Http\Controllers;

use App\Models\LeadTransaction;
use Illuminate\Http\Request;

class LeadTransactionController extends Controller
{
    /**
     * Get all transactions for a given company.
     */
    public function transactionsByCompany($companyId)
    {
        $transactions = LeadTransaction::where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'company_id'   => $companyId,
            'transactions' => $transactions
        ]);
    }

    /**
     * Get all transactions for a given lead.
     */
    public function transactionsByLead($leadId)
    {
        $transactions = LeadTransaction::where('lead_id', $leadId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'lead_id'      => $leadId,
            'transactions' => $transactions
        ]);
    }
}
