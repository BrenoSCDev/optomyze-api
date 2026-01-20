<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationSettingController extends Controller
{
    public function store()
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User has no company'], 403);
        }

        $settings = NotificationSetting::firstOrCreate(
            ['company_id' => $user->company_id],
            [
                'channels' => config('notification_defaults.channels'),
                'default_recipients' => config('notification_defaults.default_recipients'),
                'events' => config('notification_defaults.events'),
                'quiet_hours' => config('notification_defaults.quiet_hours'),
            ]
        );

        return response()->json($settings, 201);
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $settings = NotificationSetting::where('company_id', $user->company_id)->firstOrFail();

        $data = $request->validate([
            'channels' => 'array',
            'default_recipients' => 'array',
            'events' => 'array',
            'quiet_hours' => 'array',
        ]);

        foreach ($data as $key => $value) {
            $settings->{$key} = array_replace_recursive(
                $settings->{$key},
                $value
            );
        }

        $settings->save();

        return response()->json($settings);
    }
}
