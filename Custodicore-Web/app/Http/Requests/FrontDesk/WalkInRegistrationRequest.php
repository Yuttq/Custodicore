<?php

namespace App\Http\Requests\FrontDesk;

use App\Models\Pdl;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Services\RelationshipRequirements;
use App\Services\WalkInService;
use App\Support\Sanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * POST front-desk/walk-ins — registers a walk-in visitor at the desk. Asks
 * for what the app's registration asks for, except that the relationship
 * documents are required: the visitor brings them to the desk. The desk
 * selects the PDL (partial-name search) and a date and session from those
 * Records opened (WalkInService::openSlots()); the Record Officer verifies
 * the relationship, and approving books that session.
 *
 * Every text field is trimmed, its HTML/script tags are stripped and a field
 * of only spaces counts as empty (Sanitizer) before it is validated. The
 * page (walk-ins/create) runs the same rules with the same messages.
 * The Front Desk does not record ID numbers, only the ID type and a copy.
 */
class WalkInRegistrationRequest extends FrontDeskRequest
{
    /** Letters (Ñ/ñ and other accented letters too), spaces, periods, hyphens, apostrophes. */
    public const NAME_CHARACTERS = "/^[\\p{L}\\p{M} .'’-]+$/u";

    /** JPG, PNG or PDF, at most 5 MB (in kilobytes). */
    public const FILE_RULES = ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];

    public const MAX_FILES = 5;

    protected function prepareForValidation(): void
    {
        $first = Sanitizer::name($this->input('first_name'));
        $last = Sanitizer::name($this->input('last_name'));

        $this->merge([
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => trim("{$first} {$last}") ?: null,
            'address' => Sanitizer::text($this->input('address')),
            'contact_number' => self::mobile($this->input('contact_number')),
            'email' => Sanitizer::text($this->input('email')),
            'guardian_full_name' => Sanitizer::name($this->input('guardian_full_name')),
            'guardian_relationship' => Sanitizer::text($this->input('guardian_relationship')),
            'guardian_contact_number' => self::mobile($this->input('guardian_contact_number')),
            'requested_start_times' => WalkInService::normalizeTimes((array) $this->input('requested_start_times', [])),
            'visit_purpose_type' => Sanitizer::text($this->input('visit_purpose_type')),
            'visit_purpose' => Sanitizer::text($this->input('visit_purpose')),
            'id_type' => VisitorId::resolveType(Sanitizer::text($this->input('id_type'))),
        ]);
    }

    /**
     * 09XXXXXXXXX or +639XXXXXXXXX (spaces and dashes allowed), stored as
     * 09XXXXXXXXX. Anything else is kept as typed so it fails validation.
     */
    public static function mobile(mixed $value): ?string
    {
        $value = Sanitizer::text(is_string($value) ? $value : null);
        if ($value === null) {
            return null;
        }

        $compact = preg_replace('/[\s-]/', '', $value);

        return preg_match('/^\+639\d{9}$/', $compact) ? '0'.substr($compact, 3) : $compact;
    }

    public function rules(): array
    {
        $minor = $this->isMinor();
        $name = ['string', 'regex:'.self::NAME_CHARACTERS];

        return [
            'first_name' => ['required', ...$name, 'between:2,50'],
            'last_name' => ['required', ...$name, 'between:2,50'],
            'full_name' => ['required', 'string', 'max:150'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            'email' => ['nullable', 'email', 'max:100', 'unique:accounts,email'],
            'address' => ['required', 'string', 'between:5,200'],
            // A minor comes with a parent or guardian.
            'guardian_full_name' => [Rule::requiredIf($minor), 'nullable', 'string', 'max:150'],
            'guardian_relationship' => [Rule::requiredIf($minor), 'nullable', 'string', 'max:100'],
            'guardian_contact_number' => [Rule::requiredIf($minor), 'nullable', 'regex:/^09\d{9}$/'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            // Adults show a valid ID; a minor's birth certificate stands in for it.
            'id_type' => [Rule::requiredIf(! $minor), 'nullable', Rule::in(VisitorId::TYPES)],
            'government_id' => [Rule::requiredIf(! $minor), 'nullable', ...self::FILE_RULES],
            // The PDL picked from the search, then a session Records opened.
            'pdl_id' => ['required', 'integer'],
            'requested_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'requested_start_times' => ['required', 'array', 'min:1', 'max:4'],
            'requested_start_times.*' => ['date_format:H:i'],
            'visit_purpose_type' => ['required', 'string', 'max:150'],
            'visit_purpose' => [Rule::requiredIf($this->filled('visit_purpose_type')), 'nullable', 'string', 'max:150'],
            'relationship_type' => ['required', Rule::in(RelationshipRequirements::registrationTypes())],
            'has_children_together' => [Rule::requiredIf($this->input('relationship_type') === 'live_in_partner'), 'nullable', 'boolean'],
            'documents' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'documents.*' => self::FILE_RULES,
            'consent' => ['accepted'],
            // Ticked after the officer checked a possible duplicate (see after()).
            'not_duplicate' => ['nullable', 'boolean'],
        ];
    }

    /** The documents the relationship needs, each required (the visitor has them at the desk). */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The PDL must exist and still be detained.
                if ($this->filled('pdl_id') && ! $validator->errors()->has('pdl_id')) {
                    $pdl = Pdl::find($this->integer('pdl_id'));
                    if (! $pdl || $pdl->custody_status !== 'active') {
                        $validator->errors()->add('pdl_id', 'The selected PDL was not found or is no longer detained.');
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->requiredDocumentKeys() as $key) {
                    if (! $this->hasFile("documents.{$key}")) {
                        $validator->errors()->add("documents.{$key}", 'Please upload the required document.');
                    }
                }

                $files = count(array_filter((array) $this->file('documents', []))) + ($this->hasFile('government_id') ? 1 : 0);
                if ($files > self::MAX_FILES) {
                    $validator->errors()->add('documents', 'You can upload up to '.self::MAX_FILES.' files only.');
                }

                if (! $this->boolean('not_duplicate') && $duplicate = $this->possibleDuplicate()) {
                    $validator->errors()->add('duplicate', 'A visitor with this name and birthdate is already registered.');
                    session()->flash('duplicate_visitor_id', $duplicate->visitor_id);
                }

                // The chosen session must be open and bookable now (checked again when Records approves).
                try {
                    app(WalkInService::class)->assertSlotOpen(
                        Pdl::findOrFail($this->integer('pdl_id')),
                        null,
                        (string) $this->input('relationship_type'),
                        (string) $this->input('requested_date'),
                        (array) $this->input('requested_start_times'),
                    );
                } catch (ValidationException $e) {
                    $message = collect($e->errors())->flatten()->first();
                    // No session opened that day: visits are not allowed on it.
                    str_starts_with((string) $message, 'Records has not opened a session')
                        ? $validator->errors()->add('requested_date', 'Visits are not allowed on the selected day.')
                        : $validator->errors()->add('requested_start_times', $message);
                }
            },
        ];
    }

    /** @return array<int, string> */
    public function requiredDocumentKeys(): array
    {
        $type = (string) $this->input('relationship_type');
        $children = $this->input('has_children_together');
        $keys = RelationshipRequirements::documentKeysFor($type, $children === null || $children === '' ? null : $this->boolean('has_children_together'));
        if ($this->isMinor()) {
            $keys[] = RelationshipRequirements::MINOR_DOCUMENT;
        }

        return array_values(array_unique($keys));
    }

    public function possibleDuplicate(): ?VisitorProfile
    {
        return VisitorProfile::whereRaw('LOWER(full_name) = ?', [mb_strtolower((string) $this->input('full_name'))])
            ->whereDate('date_of_birth', (string) $this->input('date_of_birth'))
            ->first();
    }

    public function isMinor(): bool
    {
        try {
            return $this->filled('date_of_birth')
                && Carbon::parse($this->input('date_of_birth'))->age < RelationshipRequirements::ADULT_AGE;
        } catch (\Throwable) {
            return false;
        }
    }

    public function messages(): array
    {
        $fileType = 'Only JPG, PNG, or PDF files are allowed.';
        $fileSize = 'Each file must not exceed 5 MB.';

        return [
            'first_name.required' => 'Please enter the visitor’s first name.',
            'first_name.regex' => 'First name can only contain letters, spaces, periods, hyphens, and apostrophes.',
            'first_name.between' => 'First name must be 2 to 50 characters.',
            'last_name.required' => 'Please enter the visitor’s last name.',
            'last_name.regex' => 'Last name can only contain letters, spaces, periods, hyphens, and apostrophes.',
            'last_name.between' => 'Last name must be 2 to 50 characters.',
            'gender.*' => 'Please select the visitor’s sex.',
            'date_of_birth.required' => 'Please enter the visitor’s date of birth.',
            'date_of_birth.date' => 'Please enter the visitor’s date of birth.',
            'date_of_birth.before_or_equal' => 'Date of birth cannot be in the future.',
            'contact_number.required' => 'Please enter a contact number.',
            'contact_number.regex' => 'Enter a valid mobile number (e.g. 09171234567).',
            'email.unique' => 'This email is already used by another account.',
            'address.required' => 'Please enter the visitor’s complete address.',
            'address.between' => 'Address must be 5 to 200 characters.',
            'guardian_full_name.required' => 'Visitors under 18 must be accompanied by an adult.',
            'guardian_relationship.required' => 'Enter the guardian’s relationship to the visitor.',
            'guardian_contact_number.required' => 'Please enter a contact number.',
            'guardian_contact_number.regex' => 'Enter a valid mobile number (e.g. 09171234567).',
            'photo.required' => 'Please capture or upload the visitor’s photo.',
            'photo.mimes' => 'Photo must be a JPG or PNG file.',
            'photo.file' => 'Photo must be a JPG or PNG file.',
            'photo.max' => 'Photo must not exceed 5 MB.',
            'id_type.required' => 'Select the type of valid ID the visitor showed.',
            'government_id.required' => 'Please upload the required document.',
            'government_id.mimes' => $fileType,
            'government_id.file' => $fileType,
            'government_id.max' => $fileSize,
            'pdl_id.*' => 'Please select the PDL to be visited.',
            'requested_date.required' => 'Please select a visit date.',
            'requested_date.date_format' => 'Please select a visit date.',
            'requested_date.after_or_equal' => 'Visit date cannot be in the past.',
            'requested_start_times.*' => 'Choose a session for the visit.',
            'visit_purpose_type.*' => 'Please select the purpose of the visit.',
            'visit_purpose.required' => 'Please specify the purpose of the visit.',
            'visit_purpose.max' => 'Keep the purpose of the visit under 150 characters.',
            'relationship_type.*' => 'Please select the visitor’s relationship to the PDL.',
            'has_children_together.required' => 'Say whether the visitor and the PDL have children together.',
            'documents.max' => 'You can upload up to '.self::MAX_FILES.' files only.',
            'documents.*.mimes' => $fileType,
            'documents.*.file' => $fileType,
            'documents.*.max' => $fileSize,
            'consent.accepted' => 'The visitor must agree to the Terms and Conditions and the Privacy Policy before proceeding.',
        ];
    }
}
