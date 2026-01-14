<?php

namespace App\Http\Controllers;

use App\Models\ConversationReport;
use Illuminate\Http\Request;

class ConversationReportController extends Controller
{
    /**
     * Store a new ConversationReport.
     */
    public function store(Request $request)
    {
        $report = ConversationReport::createReport($request->all());
        return response()->json($report, 201);
    }

    /**
     * Get all reports for a specific lead.
     */
    public function getByLead($leadId)
    {
        $reports = ConversationReport::forLead($leadId);
        return response()->json($reports);
    }

    /**
     * Get all reports for a specific agent.
     */
    public function getByAgent($agentId)
    {
        $reports = ConversationReport::forAgent($agentId);
        return response()->json($reports);
    }
}
