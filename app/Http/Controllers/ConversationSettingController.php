<?php

namespace App\Http\Controllers;

use App\Models\ConversationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConversationSettingController extends Controller
{
    public function store()
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User has no company'], 403);
        }

        $settings = ConversationSetting::firstOrCreate(
            ['company_id' => $user->company_id],
            [
                'welcome_messages_enabled' => false,
                'closing_messages_enabled' => false,
            ]
        );

        return response()->json($settings, 201);
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $settings = ConversationSetting::where('company_id', $user->company_id)->firstOrFail();

        $data = $request->validate([
            'welcome_messages_enabled' => 'boolean',
            'closing_messages_enabled' => 'boolean',
        ]);

        $settings->update($data);

        return response()->json($settings);
    }
}
