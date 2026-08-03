<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Funnel;
use App\Models\Identity;
use App\Support\CompanySelectionToken;
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
     * Step one of signing in: check the person's credentials.
     *
     * Credentials belong to an identity, not to any one company account. What
     * comes back depends on how many companies this person can act in:
     *
     *   one   → a session, in exactly the shape this endpoint has always
     *           returned, so single-company users and any existing client see
     *           no change at all
     *   many  → the list to choose from plus a short-lived selection token,
     *           redeemed at /auth/select-company
     *   none  → 403
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $identity = Identity::where('email', $credentials['email'])->first();

        // Same message either way: whether an address is registered is not
        // something an unauthenticated caller should be able to probe.
        if (!$identity || !Hash::check($credentials['password'], $identity->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$identity->is_active) {
            return response()->json(['message' => 'User account is inactive'], 403);
        }

        $accounts = $identity->selectableAccounts()->get();

        if ($accounts->isEmpty()) {
            return response()->json([
                'message' => 'This account is not active at any company.',
            ], 403);
        }

        if ($accounts->count() === 1) {
            return response()->json($this->sessionPayload($accounts->first(), $identity));
        }

        return response()->json([
            'requires_company_selection' => true,
            'selection_token'            => CompanySelectionToken::issue($identity),
            'expires_in'                 => CompanySelectionToken::ttlSeconds(),
            'companies'                  => $this->companyOptions($accounts),
        ]);
    }

    /**
     * Step two: exchange the selection token for a session in one company.
     *
     * Membership is re-checked here rather than trusted from the client — the
     * list handed out in step one is a convenience, not an authorisation.
     */
    public function selectCompany(Request $request)
    {
        $validated = $request->validate([
            'selection_token' => ['required', 'string'],
            'company_id'      => ['required', 'integer'],
        ]);

        $identity = CompanySelectionToken::resolve($validated['selection_token']);

        if (!$identity) {
            return response()->json([
                'message' => 'Company selection has expired. Please sign in again.',
            ], 401);
        }

        $account = $identity->accountForCompany($validated['company_id']);

        if (!$account) {
            return response()->json(['message' => 'You do not have access to that company.'], 403);
        }

        return response()->json($this->sessionPayload($account, $identity));
    }

    /**
     * Move an authenticated session to another of the person's companies.
     *
     * The current token is destroyed and a new one minted against the target
     * account, so the session cannot straddle two tenants even briefly. The
     * client must discard every cached response it holds — see resetSession()
     * on the frontend.
     */
    public function switchCompany(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $current  = $request->user();
        $identity = $current->identity;

        if (!$identity || !$identity->is_active) {
            return response()->json(['message' => 'User account is inactive'], 403);
        }

        $account = $identity->accountForCompany($validated['company_id']);

        if (!$account) {
            return response()->json(['message' => 'You do not have access to that company.'], 403);
        }

        if ((int) $account->id === (int) $current->id) {
            return response()->json($this->sessionPayload($account, $identity));
        }

        // Revoke only the token being replaced. Other sessions this person has
        // open — including in other companies — are independent and survive.
        $current->currentAccessToken()?->delete();

        return response()->json($this->sessionPayload($account, $identity));
    }

    /**
     * The companies the signed-in person may switch to. Drives the switcher.
     */
    public function companies(Request $request)
    {
        $identity = $request->user()->identity;

        if (!$identity) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data'               => $this->companyOptions($identity->selectableAccounts()->get()),
            'active_company_id'  => $request->user()->company_id,
        ]);
    }

    /**
     * Builds the login response for one company account.
     *
     * The token is minted against the account, and an account row carries
     * exactly one company_id — so the active tenant is a property of the
     * credential itself, not of a header or session value a later request
     * could omit, forge or forget to send.
     */
    private function sessionPayload(User $account, Identity $identity): array
    {
        $account->updateLastLogin();
        $identity->updateLastLogin();

        $token = $account->createToken('auth_token')->plainTextToken;

        $account->setAttribute('company_name', $account->company?->name);

        // Same rule as FunnelController@navigationFunnels: contact funnels are
        // only navigable when the company runs contact pipelines.
        $navTypes = ['deal'];
        if ($account->company?->contact_pipeline_enabled) {
            $navTypes[] = 'contact';
        }

        $funnels = Funnel::where('company_id', $account->company_id)
            ->where(['is_active' => true])
            ->whereIn('type', $navTypes)
            ->get()
            ->map(function ($funnel) {
                $funnel->nleads = $funnel->totalLeadsCount();
                return $funnel;
            });

        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $account,
            'funnels'      => $funnels,
            'permissions'  => [],
            // Lets the client render the switcher without a second round trip.
            'companies'    => $this->companyOptions($identity->selectableAccounts()->get()),
        ];
    }

    /** Shape of a company in the picker and the switcher. */
    private function companyOptions($accounts): array
    {
        return $accounts->map(fn (User $account) => [
            'company_id' => $account->company_id,
            'name'       => $account->company?->name,
            'logo_path'  => $account->company?->logo_path,
            'role'       => $account->role,
        ])->values()->all();
    }

    /**
     * Logout (revoke tokens for this company account)
     *
     * Scoped to the account rather than the person: signing out of one company
     * leaves a session open in another company untouched, which is what
     * "independent account per company" means.
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
            if ($user->profile_pic && Storage::disk('local')->exists($user->profile_pic)) {
                Storage::disk('local')->delete($user->profile_pic);
            }

            $path = $request->file('profile_pic')->store('profile_pics/' . $user->company_id, 'local');

            $user->profile_pic = $path;
        }

        // Handle bg_pic upload
        if ($request->hasFile('bg_pic')) {
            if ($user->bg_pic && Storage::disk('local')->exists($user->bg_pic)) {
                Storage::disk('local')->delete($user->bg_pic);
            }

            $bg_path = $request->file('bg_pic')->store('bg_pics/' . $user->company_id, 'local');

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

        // The identity holds the credential that login actually checks; the
        // user row's copy is kept in step only so a rollback of the
        // application code still authenticates. See the migration notes.
        $identity = $user->identity;

        if (!$identity || !Hash::check($request->old_password, $identity->password)) {
            throw ValidationException::withMessages([
                'old_password' => ['The provided password does not match our records.'],
            ]);
        }

        $this->setPassword($identity, $request->new_password);

        return response()->json([
            'message' => 'Password updated successfully.'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        // 1. Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:identities,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $request->email;

        // The reset is identity-wide — one credential covers every company the
        // person belongs to. Only the identity is handed to the mail webhook;
        // sending the company accounts would tell it where else they work.
        $identity = Identity::where('email', $email)->first();
        $user = $identity ? collect([$identity->only(['id', 'name', 'email'])]) : collect();

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
        $resetUrl = 'https://crm.optomyze.io' . '/reset-password?token=' . $token . '&email=' . urlencode($email);    
        
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

    /**
     * Return the authenticated user's resolved permission keys.
     */
    public function myPermissions(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'role'        => $user->role,
            'permissions' => [],
        ]);
    }

    public function resetPassword(Request $request)
    {
        // 1. Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:identities,email',
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

        // 5. Update the person's password — one credential covers every
        //    company they belong to, so a reset is identity-wide.
        $identity = Identity::where('email', $request->email)->first();

        if (!$identity) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 404);
        }

        $this->setPassword($identity, $request->password);

        // 6. Delete the used token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ], 200);
    }

    /**
     * Writes a new password to the identity and mirrors it onto that person's
     * company accounts.
     *
     * The mirror is transitional. `users.password` is no longer read when
     * signing in, but keeping it current means the previous release still
     * authenticates if this one has to be rolled back. Both columns cast
     * `password` as `hashed`, so the plaintext is hashed once per model.
     */
    private function setPassword(Identity $identity, string $plaintext): void
    {
        $identity->password = $plaintext;
        $identity->save();

        User::where('identity_id', $identity->id)
            ->update(['password' => $identity->getAttributes()['password']]);
    }
}
