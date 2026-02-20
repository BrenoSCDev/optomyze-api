<?php

namespace App\Http\Controllers;

use App\Models\SaleTransaction;

class SaleTransactionController extends Controller
{
    /**
     * Get all transactions for a specific sale.
     */
    public function transactionsBySale($saleId)
    {
        $transactions = SaleTransaction::with([
                'sale:id,name,lead_id,company_id',
                'user:id,name,email',
            ])
            ->where('sale_id', $saleId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'sale_id'      => $saleId,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Get all sale transactions for a given company.
     */
    public function transactionsByCompany($companyId)
    {
        $transactions = SaleTransaction::with([
                'sale:id,name,lead_id,company_id',
                'user:id,name,email',
            ])
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'company_id'   => $companyId,
            'transactions' => $transactions,
        ]);
    }
}
