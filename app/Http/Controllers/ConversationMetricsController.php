<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ClosingReason;
use App\Models\InboxMessage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConversationMetricsController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;
        [$start, $end] = $this->resolvePeriod($request->input('period', '30_days'));

        $filters = $this->extractFilters($request);

        $startedQuery = $this->baseQuery($companyId, $filters);
        if ($start) {
            $startedQuery->whereBetween('chat_sessions.started_at', [$start, $end]);
        }
        $started = (clone $startedQuery)->count();

        $closedQuery = $this->baseQuery($companyId, $filters)->where('chat_sessions.status', ChatSession::STATUS_CLOSED);
        if ($start) {
            $closedQuery->whereBetween('chat_sessions.ended_at', [$start, $end]);
        }
        $closedSessions = (clone $closedQuery)->with('closingReason:id,outcome,name')->get();
        $closed = $closedSessions->count();

        $active = $this->baseQuery($companyId, $filters)->where('chat_sessions.status', ChatSession::STATUS_ACTIVE)->count();

        $sessionsInRange = (clone $startedQuery)->with(['messages' => fn ($q) => $q->orderBy('sent_at')])->get();
        [$avgFirstResponse, $avgResponse] = $this->computeResponseTimes($sessionsInRange);

        $avgDuration = $closedSessions->isEmpty()
            ? 0
            : (int) round($closedSessions->avg(fn ($s) => abs($s->started_at->diffInSeconds($s->ended_at))));

        $wonClosed = $closedSessions->filter(fn ($s) => $s->closingReason?->outcome === ClosingReason::OUTCOME_WON)->count();
        $lostClosed = $closedSessions->filter(fn ($s) => $s->closingReason?->outcome === ClosingReason::OUTCOME_LOST)->count();

        $conversionRate = $closed > 0 ? round($wonClosed / $closed * 100, 1) : 0;
        $resolutionRate = $closed > 0 ? round(($closed - $lostClosed) / $closed * 100, 1) : 0;

        $leadsWithSession = (clone $startedQuery)->distinct('chat_sessions.lead_id')->count('chat_sessions.lead_id');
        $leadsWithWon = (clone $closedQuery)->whereHas('closingReason', fn ($q) => $q->where('outcome', ClosingReason::OUTCOME_WON))
            ->distinct('chat_sessions.lead_id')->count('chat_sessions.lead_id');
        $leadToSaleRate = $leadsWithSession > 0 ? round($leadsWithWon / $leadsWithSession * 100, 1) : 0;

        $reopened = (clone $startedQuery)
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('chat_sessions as earlier')
                    ->whereColumn('earlier.inbox_chat_id', 'chat_sessions.inbox_chat_id')
                    ->whereColumn('earlier.id', '<', 'chat_sessions.id');
            })
            ->count();

        return response()->json([
            'data' => [
                'conversations_started' => $started,
                'conversations_closed' => $closed,
                'active_conversations' => $active,
                'avg_first_response_time_seconds' => $avgFirstResponse,
                'avg_response_time_seconds' => $avgResponse,
                'avg_conversation_duration_seconds' => $avgDuration,
                'conversion_rate' => $conversionRate,
                'resolution_rate' => $resolutionRate,
                'lead_to_sale_rate' => $leadToSaleRate,
                'reopened_conversations' => $reopened,
                'by_channel' => $this->breakdownByChannel($companyId, $filters, $start, $end),
                'by_connected_account' => $this->breakdownByAccount($companyId, $filters, $start, $end),
                'by_closing_reason' => $this->breakdownByClosingReason($companyId, $filters, $start, $end),
                'by_agent' => $this->breakdownByAgent($companyId, $filters, $start, $end),
                'agent_productivity' => $this->agentProductivity($companyId, $filters, $start, $end),
            ],
        ]);
    }

    public function dailyReport(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $companyId = $user->company_id;
        $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::today();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $started = ChatSession::where('company_id', $companyId)->whereBetween('started_at', [$start, $end])->count();

        $closedSessions = ChatSession::where('company_id', $companyId)
            ->where('status', ChatSession::STATUS_CLOSED)
            ->whereBetween('ended_at', [$start, $end])
            ->with('closingReason:id,name')
            ->get();

        $byClosingReason = $closedSessions->groupBy(fn ($s) => $s->closingReason?->name ?? 'Unknown')
            ->map(fn ($group) => $group->count());

        $unanswered = ChatSession::where('company_id', $companyId)
            ->where('status', ChatSession::STATUS_ACTIVE)
            ->whereBetween('started_at', [$start, $end])
            ->whereDoesntHave('messages', fn ($q) => $q->where('direction', 'outgoing'))
            ->count();

        $missed = ChatSession::where('company_id', $companyId)
            ->where('status', ChatSession::STATUS_CLOSED)
            ->whereBetween('ended_at', [$start, $end])
            ->whereDoesntHave('messages', fn ($q) => $q->where('direction', 'outgoing'))
            ->count();

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'conversations_started' => $started,
                'conversations_closed' => $closedSessions->count(),
                'by_closing_reason' => $byClosingReason,
                'unanswered_conversations' => $unanswered,
                'missed_conversations' => $missed,
            ],
        ]);
    }

    private function extractFilters(Request $request): array
    {
        return [
            'agent_id' => $request->input('agent_id'),
            'team_id' => $request->input('team_id'),
            'channel' => $request->input('channel'),
            'channelable_type' => $request->input('channelable_type'),
            'channelable_id' => $request->input('channelable_id'),
            'closing_reason_id' => $request->input('closing_reason_id'),
        ];
    }

    /**
     * Base ChatSession query, all columns qualified with `chat_sessions.`
     * since breakdown methods join in `leads`/`closing_reasons`/`users`
     * (which have their own colliding `id`/`company_id` columns).
     */
    private function baseQuery(int $companyId, array $filters)
    {
        $query = ChatSession::query()->where('chat_sessions.company_id', $companyId);

        if (!empty($filters['channel'])) {
            $query->where('chat_sessions.channel', $filters['channel']);
        }

        if (!empty($filters['channelable_type']) && !empty($filters['channelable_id'])) {
            $query->where('chat_sessions.channelable_type', $filters['channelable_type'])
                ->where('chat_sessions.channelable_id', $filters['channelable_id']);
        }

        if (!empty($filters['closing_reason_id'])) {
            $query->where('chat_sessions.closing_reason_id', $filters['closing_reason_id']);
        }

        if (!empty($filters['agent_id'])) {
            $query->whereHas('lead', fn ($q) => $q->where('assigned_to', $filters['agent_id']));
        }

        if (!empty($filters['team_id'])) {
            $query->whereHas('lead.assignedUser.teams', fn ($q) => $q->where('teams.id', $filters['team_id']));
        }

        return $query;
    }

    /**
     * Resolve a period string into [start, end] Carbon instances, or [null, null] for 'full'.
     */
    private function resolvePeriod(string $period): array
    {
        $now = Carbon::now();
        $make = fn (int $days) => [$now->copy()->subDays($days)->startOfDay(), $now->copy()->endOfDay()];

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7_days' => $make(7),
            '60_days' => $make(60),
            '90_days' => $make(90),
            '120_days' => $make(120),
            'full' => [null, null],
            default => $make(30),
        };
    }

    /**
     * Pair each inbound message with the next outbound reply in the same
     * session to compute average first-response and average response times.
     *
     * @param \Illuminate\Support\Collection<int, ChatSession> $sessions
     */
    private function computeResponseTimes($sessions): array
    {
        $firstResponseSeconds = [];
        $allResponseSeconds = [];

        foreach ($sessions as $session) {
            $messages = $session->messages;
            $isFirst = true;

            foreach ($messages as $index => $message) {
                if ($message->direction !== 'incoming' || !$message->sent_at) {
                    continue;
                }

                $nextOutgoing = $messages->slice($index + 1)->first(fn ($m) => $m->direction === 'outgoing' && $m->sent_at);

                if (!$nextOutgoing) {
                    continue;
                }

                $seconds = abs($message->sent_at->diffInSeconds($nextOutgoing->sent_at));
                $allResponseSeconds[] = $seconds;

                if ($isFirst) {
                    $firstResponseSeconds[] = $seconds;
                    $isFirst = false;
                }
            }
        }

        $avg = fn (array $values) => count($values) > 0 ? (int) round(array_sum($values) / count($values)) : 0;

        return [$avg($firstResponseSeconds), $avg($allResponseSeconds)];
    }

    private function breakdownByChannel(int $companyId, array $filters, $start, $end)
    {
        $query = $this->baseQuery($companyId, $filters);
        if ($start) {
            $query->whereBetween('chat_sessions.started_at', [$start, $end]);
        }

        return $query->select('chat_sessions.channel', DB::raw('COUNT(*) as count'))
            ->groupBy('chat_sessions.channel')
            ->pluck('count', 'channel');
    }

    private function breakdownByAccount(int $companyId, array $filters, $start, $end)
    {
        $query = $this->baseQuery($companyId, $filters);
        if ($start) {
            $query->whereBetween('chat_sessions.started_at', [$start, $end]);
        }

        return $query->select('chat_sessions.channelable_type', 'chat_sessions.channelable_id', 'chat_sessions.channel', DB::raw('COUNT(*) as count'))
            ->groupBy('chat_sessions.channelable_type', 'chat_sessions.channelable_id', 'chat_sessions.channel')
            ->get();
    }

    private function breakdownByClosingReason(int $companyId, array $filters, $start, $end)
    {
        $query = $this->baseQuery($companyId, $filters)->where('chat_sessions.status', ChatSession::STATUS_CLOSED);
        if ($start) {
            $query->whereBetween('chat_sessions.ended_at', [$start, $end]);
        }

        return $query->join('closing_reasons', 'closing_reasons.id', '=', 'chat_sessions.closing_reason_id')
            ->select('closing_reasons.name', 'closing_reasons.outcome', DB::raw('COUNT(*) as count'))
            ->groupBy('closing_reasons.id', 'closing_reasons.name', 'closing_reasons.outcome')
            ->get();
    }

    private function breakdownByAgent(int $companyId, array $filters, $start, $end)
    {
        $query = $this->baseQuery($companyId, $filters);
        if ($start) {
            $query->whereBetween('chat_sessions.started_at', [$start, $end]);
        }

        return $query->join('leads', 'leads.id', '=', 'chat_sessions.lead_id')
            ->join('users', 'users.id', '=', 'leads.assigned_to')
            ->select('users.id as agent_id', 'users.name as agent_name', DB::raw('COUNT(*) as count'))
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('count')
            ->get();
    }

    private function agentProductivity(int $companyId, array $filters, $start, $end)
    {
        $closedQuery = $this->baseQuery($companyId, $filters)->where('chat_sessions.status', ChatSession::STATUS_CLOSED);
        if ($start) {
            $closedQuery->whereBetween('chat_sessions.ended_at', [$start, $end]);
        }

        $closedByAgent = $closedQuery->join('leads', 'leads.id', '=', 'chat_sessions.lead_id')
            ->join('users', 'users.id', '=', 'leads.assigned_to')
            ->select(
                'users.id as agent_id',
                'users.name as agent_name',
                DB::raw('COUNT(*) as closed_count'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, chat_sessions.started_at, chat_sessions.ended_at)) as avg_duration_seconds')
            )
            ->groupBy('users.id', 'users.name')
            ->get()
            ->keyBy('agent_id');

        $messagesQuery = InboxMessage::query()
            ->join('chat_sessions', 'chat_sessions.id', '=', 'inbox_messages.chat_session_id')
            ->where('chat_sessions.company_id', $companyId)
            ->where('inbox_messages.direction', 'outgoing')
            ->whereNotNull('inbox_messages.user_id');
        if ($start) {
            $messagesQuery->whereBetween('inbox_messages.sent_at', [$start, $end]);
        }

        $messagesByAgent = $messagesQuery
            ->join('users', 'users.id', '=', 'inbox_messages.user_id')
            ->select('users.id as agent_id', 'users.name as agent_name', DB::raw('COUNT(*) as messages_sent'))
            ->groupBy('users.id', 'users.name')
            ->get()
            ->keyBy('agent_id');

        $agentIds = $closedByAgent->keys()->merge($messagesByAgent->keys())->unique();

        return $agentIds->map(function ($agentId) use ($closedByAgent, $messagesByAgent) {
            $closed = $closedByAgent->get($agentId);
            $messages = $messagesByAgent->get($agentId);

            return [
                'agent_id' => $agentId,
                'agent_name' => $closed?->agent_name ?? $messages?->agent_name,
                'closed_count' => $closed?->closed_count ?? 0,
                'avg_duration_seconds' => $closed?->avg_duration_seconds ? (int) round($closed->avg_duration_seconds) : 0,
                'messages_sent' => $messages?->messages_sent ?? 0,
            ];
        })->values();
    }
}
