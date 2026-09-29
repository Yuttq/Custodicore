<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Role;
use App\Models\VisitorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Backs src/services/api.js's login()/register()/loginWithGoogle() calls
 * from the mobile app. Every account (staff AND visitor) lives in the same
 * `accounts` table (see App\Models\Account) — a visitor logging in here is
 * just an Account with role 'Visitor' and a linked VisitorProfile.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $account = Account::where('email', $data['email'])->first();

        if (! $account || ! Hash::check($data['password'], $account->password_hash)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }

        if ($account->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'This account is not active. Contact facility staff.']);
        }

        $account->update(['last_login_at' => now()]);

        $token = $account->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($account),
        ]);
    }

    /**
     * Not implemented — verifying a Google ID token server-side needs the
     * `google/apiclient` (or similar) package and a configured OAuth client,
     * neither of which are wired up yet. Returning a clear error here
     * instead of a broken/fake success, so the mobile app's existing
     * "Google Sign-In failed" handling (see socialAuthHandlers.js) shows
     * something honest rather than silently pretending to work.
     */
    public function loginWithGoogle(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Google Sign-In is not wired up on the backend yet.',
        ], 501);
    }

    /**
     * Registers a new visitor account. Field names here are a best-effort
     * guess at what RegisterScreen.js sends (that 30KB multi-step screen
     * wasn't fully audited this pass) — accepts common snake_case AND
     * camelCase spellings for each field so small naming differences don't
     * hard-fail registration; adjust to match exactly once RegisterScreen's
     * actual submit payload is confirmed.
     */
    public function register(Request $request): JsonResponse
    {
        $fullName = $request->input('fullName', $request->input('full_name'));
        $email = $request->input('email');
        $password = $request->input('password');
        $contactNumber = $request->input('contactNumber', $request->input('contact_number'));
        $dateOfBirth = $request->input('dateOfBirth', $request->input('date_of_birth'));

        $request->validate([
            'email' => ['required', 'email', 'unique:accounts,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if (! $fullName || ! $contactNumber || ! $dateOfBirth) {
            throw ValidationException::withMessages([
                'full_name' => 'Missing required registration fields (full name, contact number, or date of birth). '
                    . 'This endpoint expects fullName/contactNumber/dateOfBirth (or their snake_case equivalents) — '
                    . 'check RegisterScreen.js\'s actual submit payload against this if registration keeps failing.',
            ]);
        }

        $visitorRole = Role::where('role_name', 'Visitor')->firstOrFail();

        $account = DB::transaction(function () use ($visitorRole, $email, $password, $fullName, $contactNumber, $dateOfBirth) {
            $account = Account::create([
                'role_id' => $visitorRole->role_id,
                'username' => $this->uniqueUsername($email),
                'email' => $email,
                'password_hash' => Hash::make($password),
                'status' => 'active',
            ]);

            VisitorProfile::create([
                'account_id' => $account->account_id,
                'full_name' => $fullName,
                'date_of_birth' => $dateOfBirth,
                'contact_number' => $contactNumber,
                'verification_status' => 'pending',
            ]);

            return $account;
        });

        $token = $account->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($account),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    private function userPayload(Account $account): array
    {
        $account->loadMissing('visitorProfile', 'role');

        return [
            'id' => $account->account_id,
            'email' => $account->email,
            'fullName' => $account->displayName(),
            'role' => $account->role?->role_name,
            'verificationStatus' => $account->visitorProfile?->verification_status,
        ];
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::before($email, '@');
        $username = $base;
        $suffix = 1;

        while (Account::where('username', $username)->exists()) {
            $username = $base . $suffix++;
        }

        return $username;
    }
}
