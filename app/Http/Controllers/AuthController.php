<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Funnel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Login user and issue Sanctum token
     */
    // public function login(Request $request)
    // {
    //     $credentials = $request->validate([
    //         'email'    => ['required', 'email'],
    //         'password' => ['required'],
    //     ]);

    //     if (!Auth::attempt($credentials)) {
    //         throw ValidationException::withMessages([
    //             'email' => ['The provided credentials are incorrect.'],
    //         ]);
    //     }

    //     $user = Auth::user();

    //     if (!$user->is_active) {
    //         return response()->json(['message' => 'User account is inactive'], 403);
    //     }

    //     $user->updateLastLogin();

    //     $token = $user->createToken('auth_token')->plainTextToken;

    //     $user->setAttribute('company_name', $user->company ? $user->company->name : null);

    //     // Fetch funnels related to the user's company
    //     $funnels = Funnel::where('company_id', $user->company_id)
    //         ->where(['is_active' => true, 'type' => 'funnel'])
    //         ->get()
    //         ->map(function ($funnel) {
    //             $funnel->nleads = $funnel->totalLeadsCount();
    //             return $funnel;
    //         });

    //     return response()->json([
    //         'access_token' => $token,
    //         'token_type'   => 'Bearer',
    //         'user'         => $user,
    //         'funnels'      => $funnels,
    //     ]);
    // }

    public function login(Request $request)
{
    $request->validate([
        'email' => ['required', 'email'],
    ]);

    // Find the user by email
    $user = User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json(['message' => 'User not found'], 404);
    }

    if (!$user->is_active) {
        return response()->json(['message' => 'User account is inactive'], 403);
    }

    // Log the user in without checking password
    Auth::login($user);

    $user->updateLastLogin();

    $token = $user->createToken('auth_token')->plainTextToken;

    $user->setAttribute('company_name', $user->company ? $user->company->name : null);

    // Fetch funnels related to the user's company
    $funnels = Funnel::where('company_id', $user->company_id)
        ->where(['is_active' => true, 'type' => 'funnel'])
        ->get()
        ->map(function ($funnel) {
            $funnel->nleads = $funnel->totalLeadsCount();
            return $funnel;
        });

    return response()->json([
        'access_token' => $token,
        'token_type'   => 'Bearer',
        'user'         => $user,
        'funnels'      => $funnels,
    ]);
}


    /**
     * Logout (revoke tokens)
     */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh token (delete old & issue new one)
     */
    public function refresh(Request $request)
    {
        $user = $request->user();

        // delete old tokens
        $user->tokens()->delete();

        // create new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
        ]);
    }
    /**
     * Get current user
     */
    public function me(Request $request)
    {
        $user = $request->user();

        $user->setAttribute('company_name', $user->company ? $user->company->name : null);

        return response()->json($user);
    }


    /**
     * Update user profile (name, phone, profile_pic, bg_pic)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'phone'       => 'sometimes|string|max:20',
            'profile_pic' => 'sometimes|nullable|file|image|max:2048', // max 2MB
            'bg_pic'      => 'sometimes|nullable|file|image|max:4096', // max 4MB
        ]);

        // Handle profile_pic upload
        if ($request->hasFile('profile_pic')) {
            // Delete old profile pic if exists
            if ($user->profile_pic && Storage::disk('public')->exists($user->profile_pic)) {
                Storage::disk('public')->delete($user->profile_pic);
            }

            $path = $request->file('profile_pic')->store('profile_pics', 'public');
            
            $user->profile_pic = $path;
        }

        // Handle bg_pic upload
        if ($request->hasFile('bg_pic')) {
            // Delete old background pic if exists
            if ($user->bg_pic && Storage::disk('public')->exists($user->bg_pic)) {
                Storage::disk('public')->delete($user->bg_pic);
            }

            $bg_path = $request->file('bg_pic')->store('bg_pics', 'public');

            $user->bg_pic = $bg_path;
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user
        ]);
    }

    /**
     * Reset password (requires old password + new password)
     */
    public function resetPasswordAuth(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed', 
            // requires new_password_confirmation field in request
        ]);

        if (!Hash::check($request->old_password, $user->password)) {
            throw ValidationException::withMessages([
                'old_password' => ['The provided password does not match our records.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'message' => 'Password updated successfully.'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        // 1. Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $request->email;
        $user = User::where('email', $request->email)->get();

        // 2. Generate a secure random token
        $token = Str::random(64);
        
        // 3. Store token in password_reset_tokens table with expiration
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        // 4. Prepare the reset link/token for the webhook
        $resetUrl = 'http://localhost:8080' . '/reset-password?token=' . $token . '&email=' . urlencode($email);    
        
        $url = "https://optomyze-n8n.kmfrpu.easypanel.host/webhook/7f48b411-f289-4d5e-b05c-8a012a48e2db";

        // Build request body with token information
        $body = [
            'email' => $email,
            'user' => $user,
            'token' => $token,
            'reset_url' => $resetUrl,
            'expires_at' => now()->addHour()->toDateTimeString(),
        ];

        try {
            // 5. Send POST request to webhook
            $response = Http::timeout(10)->post($url, $body);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to send password reset email.',
                    'error' => $response->body(),
                ], 500);
            }

            return response()->json([
                'message' => 'Password reset email sent successfully. Please check your inbox.',
                'data' => [
                    'email' => $email,
                    'expires_in' => '1 hour',
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error sending password reset email.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        // 1. Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. Retrieve the password reset record
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
            ], 404);
        }

        $tokenAge = Carbon::parse($resetRecord->created_at);
        if ($tokenAge->addHour()->isPast()) {
            // Delete expired token
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            
            return response()->json([
                'message' => 'Password reset token has expired. Please request a new one.',
            ], 410);
        }

        // 4. Verify the token
        if (!Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'message' => 'Invalid reset token.',
            ], 401);
        }

        // 5. Update the user's password
        $user = User::where('email', $request->email)->first();
        $user->password = $request->password;
        $user->save();

        // 6. Delete the used token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // 7. Optional: Revoke all user tokens if using Sanctum/Passport
        // $user->tokens()->delete();

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ], 200);
    }
}
