<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FirstAccessService
{
    private const WEBHOOK_URL = 'https://optomyze-n8n.kmfrpu.easypanel.host/webhook-test/b9d6ea2d-0ae9-4331-a0ad-bff94512f7cf';

    /**
     * Generate a password-setup token for a newly created user (same mechanism
     * as the forgot-password flow) and send it to n8n for delivery.
     */
    public function send(User $user): void
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        $setPasswordUrl = 'http://localhost:8080/set-password?token=' . $token . '&email=' . urlencode($user->email);

        $body = [
            'email' => $user->email,
            'user' => $user,
            'token' => $token,
            'set_password_url' => $setPasswordUrl,
            'expires_at' => now()->addHour()->toDateTimeString(),
        ];

        try {
            $response = Http::timeout(10)->post(self::WEBHOOK_URL, $body);

            if ($response->failed()) {
                Log::warning('First access email webhook failed', [
                    'email' => $user->email,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending first access email', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
