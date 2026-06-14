<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('company.{companyId}', function ($user, $companyId) {
    return (int) $user->company_id === (int) $companyId ? $user : false;
});

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    return \App\Models\Conversation::where('id', $conversationId)
        ->where('company_id', $user->company_id)
        ->whereHas('participants', fn($q) => $q->where('user_id', $user->id))
        ->exists();
});

Broadcast::channel('chat-notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('appointments.{companyId}', function ($user, $companyId) {
    return (int) $user->company_id === (int) $companyId ? $user : false;
});

Broadcast::channel('funnel.{funnelId}', function ($user, $funnelId) {
    return \App\Models\Funnel::where('id', $funnelId)
        ->where('company_id', $user->company_id)
        ->exists();
});

Broadcast::channel('deal-funnel.{funnelId}', function ($user, $funnelId) {
    return \App\Models\Funnel::where('id', $funnelId)
        ->where('company_id', $user->company_id)
        ->exists();
});

Broadcast::channel('online-users.{companyId}', function ($user, $companyId) {
    if ((int) $user->company_id !== (int) $companyId) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
