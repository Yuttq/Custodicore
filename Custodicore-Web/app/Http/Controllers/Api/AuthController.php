<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\GoogleAuthDecision;
use App\Services\Auth\GoogleAuthService;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\GoogleSignInUnavailableException;
use App\Services\Auth\InvalidGoogleIdTokenException;
use App\Services\Auth\VerifiedGoogleIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
    /** The only gender values a visitor may submit (registration and PATCH /me). */
    private const GENDERS = ['male', 'female'];

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

        // The mobile API is for visitors only. Staff (Admin/Warden, Record
        // Officer, Front Desk) sign in through the web console's session
        // login; they must never receive a mobile bearer token. Checked only
        // after the password, so it reveals nothing to someone without it.
        if (! $account->isVisitor()) {
            throw ValidationException::withMessages([
                'email' => 'Staff accounts cannot sign in to the mobile app. Use the web console.',
            ]);
        }

        if ($account->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'This account is not active. Contact facility staff.']);
        }

        // Email ownership first (Phase 2). Only reached with the right
        // password, so it reveals nothing about unknown addresses. Staff
        // review (verification_status) does NOT block login — a pending or
        // rejected visitor signs in to a restricted app (EnsureVisitorApproved).
        if (! $account->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email address before logging in.',
                'code' => 'email_not_verified',
                'email' => $account->email,
            ], 403);
        }

        // forceFill: last_login_at is not fillable, so update() silently dropped it
        // (same as Auth\LoginController).
        $account->forceFill(['last_login_at' => now()])->save();

        $token = $account->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($account),
        ]);
    }

    /**
     * Google Sign-In: verifies the Google ID token server-side
     * (GoogleIdTokenVerifier), then GoogleAuthService decides what the
     * verified identity may do. Never creates or links an account.
     *
     * Only the token is read from the request; any email/name/Google ID the
     * client sends is ignored. The token is never logged or echoed back.
     *
     *   200 authenticated          {status, token, user} — linked active Visitor
     *   200 registration_required  {status, profile{email, fullName}, consentVersion}
     *                              — unknown Google user; nothing was created
     *   409 link_required          email has an account not linked to this
     *                              Google identity (never auto-linked)
     *   403 not_visitor_account    matched account is Staff
     *   403 account_inactive       matched Visitor account is not active
     *   422 idToken missing/not a string
     *   401 invalid_google_token   failed verification (signature, issuer,
     *                              audience, expiry, claims, unverified email)
     *   503 google_unavailable     not configured / Google keys unreachable
     */
    public function loginWithGoogle(Request $request, GoogleIdTokenVerifier $verifier, GoogleAuthService $googleAuth): JsonResponse
    {
        $this->aliasIdToken($request);

        $data = $request->validate([
            'idToken' => ['required', 'string'],
        ]);

        $identity = $this->verifyGoogleIdToken($verifier, $data['idToken']);
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        return $this->googleDecisionResponse($googleAuth->decide($identity));
    }

    /**
     * Explicit Google linking (after /auth/google answered link_required):
     * the verified Google identity is linked to the existing Visitor account
     * with the same verified email only once its Custodicore password is
     * confirmed. See GoogleAuthService::link() for the order of checks.
     *
     * Only idToken/id_token and password are read; any email/name/Google ID/
     * account ID the client sends is ignored. Neither the token nor the
     * password is ever logged or echoed back.
     *
     *   200 authenticated            {status, token, user} — linked now, or
     *                                already linked to this same identity
     *   404 account_not_found        no account has the verified email
     *   403 not_visitor_account      matched account is Staff
     *   403 account_inactive         matched Visitor account is not active
     *   401 invalid_password         wrong Custodicore password
     *   409 google_account_mismatch  Google identity linked to another
     *                                account, or account linked to another
     *                                Google identity
     *   422 idToken/password missing or not a string
     *   401 invalid_google_token / 503 google_unavailable — as /auth/google
     */
    public function linkGoogle(Request $request, GoogleIdTokenVerifier $verifier, GoogleAuthService $googleAuth): JsonResponse
    {
        $this->aliasIdToken($request);

        $data = $request->validate([
            'idToken' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identity = $this->verifyGoogleIdToken($verifier, $data['idToken']);
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        return $this->googleDecisionResponse($googleAuth->link($identity, $data['password']));
    }

    /** camelCase (mobile) or snake_case, as register() accepts. */
    private function aliasIdToken(Request $request): void
    {
        if (! $request->has('idToken') && $request->has('id_token')) {
            $request->merge(['idToken' => $request->input('id_token')]);
        }
    }

    /** The verified identity, or the error response to return as-is. */
    private function verifyGoogleIdToken(GoogleIdTokenVerifier $verifier, string $idToken): VerifiedGoogleIdentity|JsonResponse
    {
        try {
            return $verifier->verify($idToken);
        } catch (InvalidGoogleIdTokenException) {
            return response()->json([
                'message' => 'Google Sign-In failed. Please try again.',
                'code' => 'invalid_google_token',
            ], 401);
        } catch (GoogleSignInUnavailableException $e) {
            // Exception class/message only — never the token.
            Log::warning('Google Sign-In unavailable: '.$e->getMessage());

            return response()->json([
                'message' => 'Google Sign-In is temporarily unavailable.',
                'code' => 'google_unavailable',
            ], 503);
        }
    }

    private function googleDecisionResponse(GoogleAuthDecision $decision): JsonResponse
    {
        $identity = $decision->identity;

        return match ($decision->outcome) {
            GoogleAuthDecision::AUTHENTICATED => response()->json([
                'status' => GoogleAuthDecision::AUTHENTICATED,
                'token' => $decision->token,
                'user' => $this->userPayload($decision->account),
            ]),
            GoogleAuthDecision::REGISTRATION_REQUIRED => response()->json([
                'status' => GoogleAuthDecision::REGISTRATION_REQUIRED,
                // Verified token claims only — prefills the registration form.
                'profile' => [
                    'email' => $identity->email,
                    'fullName' => $identity->name,
                ],
                'consentVersion' => (string) config('legal.version'),
            ]),
            GoogleAuthDecision::LINK_REQUIRED => response()->json([
                'message' => 'This email already has a Custodicore account. Confirm your Custodicore password before linking Google.',
                'code' => GoogleAuthDecision::LINK_REQUIRED,
            ], 409),
            GoogleAuthDecision::NOT_VISITOR_ACCOUNT => response()->json([
                'message' => 'Google sign-in is only available for visitor accounts.',
                'code' => GoogleAuthDecision::NOT_VISITOR_ACCOUNT,
            ], 403),
            GoogleAuthDecision::ACCOUNT_INACTIVE => response()->json([
                // Same wording as the password login.
                'message' => 'This account is not active. Contact facility staff.',
                'code' => GoogleAuthDecision::ACCOUNT_INACTIVE,
            ], 403),
            GoogleAuthDecision::ACCOUNT_NOT_FOUND => response()->json([
                'message' => 'No existing Custodicore account was found for this Google account.',
                'code' => GoogleAuthDecision::ACCOUNT_NOT_FOUND,
            ], 404),
            GoogleAuthDecision::INVALID_PASSWORD => response()->json([
                'message' => 'The provided password is incorrect.',
                'code' => GoogleAuthDecision::INVALID_PASSWORD,
            ], 401),
            GoogleAuthDecision::GOOGLE_ACCOUNT_MISMATCH => response()->json([
                'message' => 'This Google account is already linked to another Custodicore account.',
                'code' => GoogleAuthDecision::GOOGLE_ACCOUNT_MISMATCH,
            ], 409),
        };
    }

    /**
     * Registers a new visitor account.
     *
     * Accepts camelCase and snake_case for mobile compatibility. Collects
     * relationshipHint as a free-text initial indication only — does NOT
     * create a visitor_pdl_relationships row (PDL may be unknown at signup).
     *
     * Gender is required and must be male or female. (visitor_profiles.gender
     * is still a nullable male|female|other enum so existing rows stay valid.)
     *
     * Phase 2: does NOT sign the visitor in. No Sanctum token or user is
     * returned; a verification link is emailed instead, and /auth/login
     * refuses the account until the link is used. The government ID picked
     * during registration is therefore accepted here (multipart:
     * governmentId + governmentIdType [+ governmentIdNumber]) and stored as
     * `pending`, exactly like POST /documents — there is no token to upload
     * it with afterwards. The profile stays `pending` until staff review it.
     *
     *   201 verification_required  {status, message, email}
     *   422 validation errors
     */
    public function register(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $this->mergeRegistrationAliases($request);
        $request->merge([
            'email' => $request->input('email'),
            'governmentIdType' => VisitorId::resolveType(
                $request->input('governmentIdType', $request->input('government_id_type'))
            ),
            'governmentIdNumber' => $request->input('governmentIdNumber', $request->input('government_id_number')),
        ]);
        if (! $request->hasFile('governmentId') && $request->hasFile('government_id')) {
            $request->files->set('governmentId', $request->file('government_id'));
        }

        $passwordRules = ['required', 'string', 'min:6'];
        if ($request->filled('password_confirmation')) {
            $passwordRules[] = 'confirmed';
        }

        $documentFileRules = array_values(array_diff(VisitorApiController::DOCUMENT_FILE_RULES, ['required']));

        $request->validate([
            'email' => ['required', 'email', 'max:100', 'unique:accounts,email'],
            'password' => $passwordRules,
            'governmentId' => ['nullable', ...$documentFileRules],
            'governmentIdType' => ['nullable', 'required_with:governmentId', 'string', Rule::in(VisitorId::TYPES)],
            'governmentIdNumber' => ['nullable', 'string', 'max:50'],
            // Overrides the shared (optional) gender rule: required here.
            'gender' => ['required', 'string', Rule::in(self::GENDERS)],
        ] + $this->profileRegistrationRules(), $this->registrationMessages() + [
            'governmentId.mimes' => 'Upload a JPG, PNG, WEBP, or PDF file.',
            'governmentId.max' => 'The file is too large. Maximum size is 10 MB.',
            'governmentIdType.in' => 'Choose one of the accepted government ID types.',
            'governmentIdType.required_with' => 'Select the type of government ID you uploaded.',
        ]);

        $email = $request->input('email');

        $account = DB::transaction(function () use ($request, $email) {
            $account = $this->createVisitorAccount($request, $email);

            if ($request->hasFile('governmentId')) {
                VisitorApiController::storeGovernmentId(
                    $account->visitorProfile,
                    $request->file('governmentId'),
                    $request->input('governmentIdType'),
                    $request->input('governmentIdNumber'),
                );
            }

            return $account;
        });

        // After commit: a mail failure never undoes the registration (the
        // visitor can request another link).
        $verification->send($account);

        return response()->json([
            'status' => 'verification_required',
            'message' => 'Your account has been created. We sent a verification link to your email address. Please verify your email before logging in.',
            'email' => $account->email,
        ], 201);
    }

    /**
     * Google registration (after /auth/google answered registration_required):
     * the same visitor registration form as register(), minus email, plus
     * the Google ID token. The visitor still sets a normal Custodicore
     * password (confirmed), so password login keeps working.
     *
     * The email and Google ID come ONLY from the verified token; any email,
     * Google ID, role or status in the body is ignored. Consent is recorded
     * server-side exactly as in register(). Nothing is created, linked or
     * issued unless all of it succeeds (GoogleAuthService::register()).
     *
     *   201 authenticated            {status, token, user} — account created
     *                                and linked to the verified Google ID
     *   409 google_account_mismatch  Google identity already linked to an account
     *   409 link_required            verified email already has an account —
     *                                use /auth/google/link, never auto-linked
     *   403 not_visitor_account      matched account is Staff
     *   403 account_inactive         matched account is not active (not reactivated)
     *   422 validation errors        form fields as register(); `email` if the
     *                                verified Google email cannot be stored
     *   401 invalid_google_token / 503 google_unavailable — as /auth/google
     */
    public function registerWithGoogle(Request $request, GoogleIdTokenVerifier $verifier, GoogleAuthService $googleAuth): JsonResponse
    {
        $this->aliasIdToken($request);
        $this->mergeRegistrationAliases($request);

        $request->validate([
            'idToken' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ] + $this->profileRegistrationRules(), $this->registrationMessages());

        $identity = $this->verifyGoogleIdToken($verifier, $request->input('idToken'));
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        // Same limit as accounts.email (and register()'s `email` rule).
        if (mb_strlen($identity->email) > 100) {
            throw ValidationException::withMessages([
                'email' => 'This Google account\'s email address is too long to register. Use the regular registration instead.',
            ]);
        }

        $decision = $googleAuth->register(
            $identity,
            fn (string $verifiedEmail) => $this->createVisitorAccount($request, $verifiedEmail, maxUsernameBase: 40),
        );

        $response = $this->googleDecisionResponse($decision);

        return $decision->outcome === GoogleAuthDecision::AUTHENTICATED
            ? $response->setStatusCode(201)
            : $response;
    }

    /**
     * Registration fields accepted as camelCase (mobile) or snake_case,
     * merged back under their camelCase names for validation.
     */
    private function mergeRegistrationAliases(Request $request): void
    {
        $request->merge([
            // Consent (Terms & Conditions + Privacy Policy) — accepted before
            // any details are collected.
            'acceptedTerms' => $request->input('acceptedTerms', $request->input('accepted_terms')),
            'acceptedPrivacy' => $request->input('acceptedPrivacy', $request->input('accepted_privacy')),
            'consentVersion' => $request->input('consentVersion', $request->input('consent_version')),
            'password' => $request->input('password'),
            'fullName' => $request->input('fullName', $request->input('full_name')),
            // Phase 1 sends First/Last Name separately too (fullName is
            // still the combined name everything else uses).
            'firstName' => $request->input('firstName', $request->input('first_name')),
            'lastName' => $request->input('lastName', $request->input('last_name')),
            'dateOfBirth' => $request->input('dateOfBirth')
                ?? $request->input('date_of_birth')
                ?? $request->input('birthdate'),
            'contactNumber' => $request->input('contactNumber', $request->input('contact_number')),
            'gender' => $request->input('gender'),
            'address' => $request->input('address'),
            'relationshipHint' => $request->input('relationshipHint', $request->input('relationship_hint')),
        ]);
    }

    /** Profile + consent rules shared by register() and registerWithGoogle(). */
    private function profileRegistrationRules(): array
    {
        return [
            'fullName' => ['required', 'string', 'max:150'],
            'firstName' => ['nullable', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'dateOfBirth' => ['required', 'date', 'before:today'],
            'contactNumber' => ['nullable', 'string', 'max:20'],
            // register() also makes it required; Google registration keeps
            // it optional as before, but neither accepts anything else.
            'gender' => ['nullable', 'string', Rule::in(self::GENDERS)],
            'address' => ['nullable', 'string', 'max:255'],
            'relationshipHint' => ['nullable', 'string', 'max:100'],
            'acceptedTerms' => ['accepted'],
            'acceptedPrivacy' => ['accepted'],
            // If the app says which version it showed, it must be the current one.
            'consentVersion' => ['nullable', 'string', Rule::in([(string) config('legal.version')])],
        ];
    }

    private function registrationMessages(): array
    {
        return [
            'acceptedTerms.accepted' => 'You must accept the Terms and Conditions to register.',
            'acceptedPrivacy.accepted' => 'You must accept the Privacy Policy to register.',
            'consentVersion.in' => 'The Terms and Privacy Policy were updated. Please review and accept the latest version.',
        ];
    }

    /**
     * Creates the Visitor account + VisitorProfile from a validated
     * registration request. Callers wrap it in a transaction. Role, status
     * and consent are set here, never from the request.
     */
    private function createVisitorAccount(Request $request, string $email, ?int $maxUsernameBase = null): Account
    {
        $consentAt = now();
        $visitorRole = Role::where('role_name', 'Visitor')->firstOrFail();

        $account = Account::create([
            'role_id' => $visitorRole->role_id,
            'username' => $this->uniqueUsername($email, $maxUsernameBase),
            'email' => $email,
            'password_hash' => Hash::make($request->input('password')),
            'status' => 'active',
            'terms_accepted_at' => $consentAt,
            'privacy_accepted_at' => $consentAt,
            'consent_version' => (string) config('legal.version'),
        ]);

        // Intentionally does NOT create visitor_pdl_relationships —
        // relationshipHint is only an initial indication for staff later.
        VisitorProfile::create([
            'account_id' => $account->account_id,
            'full_name' => $request->input('fullName'),
            'first_name' => $request->filled('firstName') ? trim($request->input('firstName')) : null,
            'last_name' => $request->filled('lastName') ? trim($request->input('lastName')) : null,
            'date_of_birth' => $request->input('dateOfBirth'),
            'gender' => $this->normalizeGender($request->input('gender')),
            'address' => $request->input('address'),
            'relationship_hint' => $request->input('relationshipHint'),
            'contact_number' => $request->input('contactNumber') ?: 'N/A',
            'verification_status' => 'pending',
        ]);

        return $account;
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
            'gender' => ['sometimes', 'required', 'string', Rule::in(self::GENDERS)],
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
            // Two separate states (Phase 2): email ownership vs. staff review
            // of the visitor's information/documents. The app restricts itself
            // unless verificationStatus === 'verified' (and so does the API).
            'emailVerified' => $account->hasVerifiedEmail(),
            'emailVerifiedAt' => $account->email_verified_at?->toIso8601String(),
            'verificationStatus' => $profile?->verification_status,
            'verifiedAt' => $profile?->verified_at?->toIso8601String(),
            // Only the visitor-facing reason staff entered, only when rejected.
            'rejectionReason' => $profile?->verification_status === 'rejected' ? $profile->rejection_reason : null,
            'firstName' => $profile?->first_name,
            'lastName' => $profile?->last_name,
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
     * Map a validated gender (see GENDERS) onto visitor_profiles.gender.
     */
    private function normalizeGender(mixed $gender): ?string
    {
        if ($gender === null || $gender === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $gender));

        return in_array($normalized, self::GENDERS, true) ? $normalized : null;
    }

    /** $maxBase keeps base + numeric suffix within accounts.username (50). */
    private function uniqueUsername(string $email, ?int $maxBase = null): string
    {
        $base = Str::before($email, '@');
        if ($maxBase !== null) {
            $base = Str::substr($base, 0, $maxBase);
        }
        $username = $base;
        $suffix = 1;

        while (Account::where('username', $username)->exists()) {
            $username = $base . $suffix++;
        }

        return $username;
    }
}
