<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\VisitorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
     * Registers a new visitor account.
     *
     * Accepts camelCase and snake_case for mobile compatibility. Collects
     * relationshipHint as a free-text initial indication only — does NOT
     * create a visitor_pdl_relationships row (PDL may be unknown at signup).
     *
     * Gender "Prefer not to say" is stored as NULL (visitor_profiles.gender
     * is a nullable enum of male|female|other).
     */
    public function register(Request $request): JsonResponse
    {
        $fullName = $request->input('fullName', $request->input('full_name'));
        $email = $request->input('email');
        $password = $request->input('password');
        $contactNumber = $request->input('contactNumber', $request->input('contact_number'));
        $dateOfBirth = $request->input('dateOfBirth')
            ?? $request->input('date_of_birth')
            ?? $request->input('birthdate');
        $genderRaw = $request->input('gender');
        $address = $request->input('address');
        $relationshipHint = $request->input('relationshipHint', $request->input('relationship_hint'));

        // Consent (Terms & Conditions + Privacy Policy) — accepted before any
        // details are collected; camelCase (mobile) or snake_case.
        $acceptedTerms = $request->input('acceptedTerms', $request->input('accepted_terms'));
        $acceptedPrivacy = $request->input('acceptedPrivacy', $request->input('accepted_privacy'));
        $consentVersion = $request->input('consentVersion', $request->input('consent_version'));

        $request->merge([
            'acceptedTerms' => $acceptedTerms,
            'acceptedPrivacy' => $acceptedPrivacy,
            'consentVersion' => $consentVersion,
            'email' => $email,
            'password' => $password,
            'fullName' => $fullName,
            'dateOfBirth' => $dateOfBirth,
            'contactNumber' => $contactNumber,
            'gender' => $genderRaw,
            'address' => $address,
            'relationshipHint' => $relationshipHint,
        ]);

        $passwordRules = ['required', 'string', 'min:6'];
        if ($request->filled('password_confirmation')) {
            $passwordRules[] = 'confirmed';
        }

        $request->validate([
            'email' => ['required', 'email', 'unique:accounts,email'],
            'password' => $passwordRules,
            'fullName' => ['required', 'string', 'max:150'],
            'dateOfBirth' => ['required', 'date', 'before:today'],
            'contactNumber' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', 'string', Rule::in([
                'male', 'female', 'other',
                'prefer_not_to_say', 'Prefer not to say', 'prefer not to say',
            ])],
            'address' => ['nullable', 'string', 'max:255'],
            'relationshipHint' => ['nullable', 'string', 'max:100'],
            'acceptedTerms' => ['accepted'],
            'acceptedPrivacy' => ['accepted'],
            // If the app says which version it showed, it must be the current one.
            'consentVersion' => ['nullable', 'string', Rule::in([(string) config('legal.version')])],
        ], [
            'acceptedTerms.accepted' => 'You must accept the Terms and Conditions to register.',
            'acceptedPrivacy.accepted' => 'You must accept the Privacy Policy to register.',
            'consentVersion.in' => 'The Terms and Privacy Policy were updated. Please review and accept the latest version.',
        ]);

        $consentAt = now();
        $consentVersionStored = (string) config('legal.version');

        $gender = $this->normalizeGender($genderRaw);
        $visitorRole = Role::where('role_name', 'Visitor')->firstOrFail();

        $account = DB::transaction(function () use (
            $visitorRole,
            $email,
            $password,
            $fullName,
            $contactNumber,
            $dateOfBirth,
            $gender,
            $address,
            $relationshipHint,
            $consentAt,
            $consentVersionStored
        ) {
            $account = Account::create([
                'role_id' => $visitorRole->role_id,
                'username' => $this->uniqueUsername($email),
                'email' => $email,
                'password_hash' => Hash::make($password),
                'status' => 'active',
                'terms_accepted_at' => $consentAt,
                'privacy_accepted_at' => $consentAt,
                'consent_version' => $consentVersionStored,
            ]);

            // Intentionally does NOT create visitor_pdl_relationships —
            // relationshipHint is only an initial indication for staff later.
            VisitorProfile::create([
                'account_id' => $account->account_id,
                'full_name' => $fullName,
                'date_of_birth' => $dateOfBirth,
                'gender' => $gender,
                'address' => $address,
                'relationship_hint' => $relationshipHint,
                'contact_number' => $contactNumber ?: 'N/A',
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

    /**
     * Authenticated visitor (or staff) profile for the mobile contract's /me.
     */
    public function me(Request $request): JsonResponse
    {
        $account = $request->user();
        abort_unless($account, 401);

        return response()->json($this->userPayload($account));
    }

    /**
     * Updates the authenticated visitor's own profile (mobile Personal
     * Information). Only existing visitor_profiles contact/identity columns
     * are editable — role, account status, email and every verification
     * field stay under staff control and are rejected outright.
     *
     * Full name / date of birth are locked once the visitor is verified, so
     * a verified identity cannot be changed from the app.
     */
    public function updateMe(Request $request): JsonResponse
    {
        $account = $request->user();
        $profile = $account?->visitorProfile;
        abort_unless($profile, 403, 'This account has no visitor profile.');

        // Accept camelCase and snake_case, as register() does.
        $aliases = [
            'fullName' => 'full_name',
            'dateOfBirth' => 'date_of_birth',
            'contactNumber' => 'contact_number',
            'emergencyContactName' => 'emergency_contact_name',
            'emergencyContactNumber' => 'emergency_contact_number',
        ];
        foreach ($aliases as $camel => $snake) {
            if (! $request->has($camel) && $request->has($snake)) {
                $request->merge([$camel => $request->input($snake)]);
            }
        }

        $prohibited = [
            'email', 'password', 'role', 'role_id', 'status', 'account_id',
            'verificationStatus', 'verification_status', 'verifiedBy', 'verified_by',
            'verifiedAt', 'verified_at', 'relationshipHint', 'relationship_hint',
        ];

        $rules = [
            'fullName' => ['sometimes', 'required', 'string', 'max:150'],
            'dateOfBirth' => ['sometimes', 'required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', Rule::in([
                'male', 'female', 'other',
                'prefer_not_to_say', 'Prefer not to say', 'prefer not to say',
            ])],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contactNumber' => ['sometimes', 'required', 'string', 'max:20'],
            'emergencyContactName' => ['sometimes', 'nullable', 'string', 'max:150'],
            'emergencyContactNumber' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
        foreach ($prohibited as $field) {
            $rules[$field] = ['prohibited'];
        }

        $data = $request->validate($rules, [
            'prohibited' => 'The :attribute field cannot be changed from the mobile app.',
        ]);

        $updates = [];
        if (array_key_exists('fullName', $data)) $updates['full_name'] = trim($data['fullName']);
        if (array_key_exists('dateOfBirth', $data)) $updates['date_of_birth'] = $data['dateOfBirth'];
        if (array_key_exists('gender', $data)) $updates['gender'] = $this->normalizeGender($data['gender']);
        if (array_key_exists('address', $data)) $updates['address'] = $data['address'] !== null ? trim($data['address']) : null;
        if (array_key_exists('contactNumber', $data)) $updates['contact_number'] = trim($data['contactNumber']);
        if (array_key_exists('emergencyContactName', $data)) $updates['emergency_contact_name'] = $data['emergencyContactName'];
        if (array_key_exists('emergencyContactNumber', $data)) $updates['emergency_contact_number'] = $data['emergencyContactNumber'];

        if ($profile->verification_status === 'verified') {
            $identityChanged =
                (isset($updates['full_name']) && $updates['full_name'] !== $profile->full_name)
                || (isset($updates['date_of_birth']) && $updates['date_of_birth'] !== $profile->date_of_birth?->format('Y-m-d'));

            if ($identityChanged) {
                return response()->json([
                    'message' => 'Your name and date of birth are locked after verification. Contact facility staff to correct them.',
                ], 409);
            }
        }

        if ($updates) {
            $profile->update($updates);
            AuditLog::record('update', 'visitor_profiles', $profile->visitor_id,
                'Visitor updated own profile via mobile app (' . implode(', ', array_keys($updates)) . ')',
                Module::CODE_VISITOR_MANAGEMENT);
        }

        $account->unsetRelation('visitorProfile');

        return response()->json($this->userPayload($account));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    private function userPayload(Account $account): array
    {
        $account->loadMissing('visitorProfile', 'role');
        $profile = $account->visitorProfile;

        return [
            'id' => (string) $account->account_id,
            'email' => $account->email,
            'fullName' => $account->displayName(),
            'role' => $account->role?->role_name,
            'verificationStatus' => $profile?->verification_status,
            'verifiedAt' => $profile?->verified_at?->toIso8601String(),
            'dateOfBirth' => $profile?->date_of_birth?->format('Y-m-d'),
            'gender' => $profile?->gender,
            'address' => $profile?->address,
            // register() stores 'N/A' when no number was given — surface as null.
            'contactNumber' => ($profile && $profile->contact_number !== 'N/A') ? $profile->contact_number : null,
            'emergencyContactName' => $profile?->emergency_contact_name,
            'emergencyContactNumber' => $profile?->emergency_contact_number,
            'relationshipHint' => $profile?->relationship_hint,
        ];
    }

    /**
     * Map mobile gender values onto the nullable male|female|other enum.
     * "Prefer not to say" → NULL.
     */
    private function normalizeGender(mixed $gender): ?string
    {
        if ($gender === null || $gender === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $gender));

        return match ($normalized) {
            'male' => 'male',
            'female' => 'female',
            'other' => 'other',
            'prefer_not_to_say', 'prefer not to say' => null,
            default => null,
        };
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
