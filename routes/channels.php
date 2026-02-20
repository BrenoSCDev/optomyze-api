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
