<?php

namespace App\Http\Requests\FrontDesk;

use App\Http\Controllers\Api\VisitorApiController;
use App\Models\VisitorId;
use App\Models\VisitorProfile;
use App\Services\RelationshipRequirements;
use App\Support\Sanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST front-desk/walk-ins — registers a walk-in visitor at the desk. Asks
 * for what the app's registration asks for, except that the relationship
 * documents are required: the visitor brings them to the desk. The PDL is
 * only the name the visitor gives; the Record Officer links the PDL.
 */
class WalkInRegistrationRequest extends FrontDeskRequest
{
    protected function prepareForValidation(): void
    {
        $first = Sanitizer::name($this->input('first_name'));
        $last = Sanitizer::name($this->input('last_name'));

        $this->merge([
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => trim("{$first} {$last}") ?: null,
            'address' => Sanitizer::text($this->input('address')),
            'contact_number' => preg_replace('/\D/', '', (string) $this->input('contact_number')) ?: null,
            'email' => Sanitizer::text($this->input('email')),
            'guardian_full_name' => Sanitizer::name($this->input('guardian_full_name')),
            'guardian_relationship' => Sanitizer::text($this->input('guardian_relationship')),
            'guardian_contact_number' => preg_replace('/\D/', '', (string) $this->input('guardian_contact_number')) ?: null,
            'intended_pdl_name' => Sanitizer::name($this->input('intended_pdl_name')),
            // PDL numbers are stored in capitals (PDL-2026-00007).
            'intended_pdl_number' => ($number = Sanitizer::text($this->input('intended_pdl_number'))) ? mb_strtoupper($number) : null,
            'visit_purpose' => Sanitizer::text($this->input('visit_purpose')),
            'id_type' => VisitorId::resolveType(Sanitizer::text($this->input('id_type'))),
            'id_number' => Sanitizer::idNumber($this->input('id_number')),
        ]);
    }

    public function rules(): array
    {
        $fileRules = array_values(array_diff(VisitorApiController::DOCUMENT_FILE_RULES, ['required']));
        $minor = $this->isMinor();

        return [
            'first_name' => ['required', 'string', 'max:100', 'regex:'.Sanitizer::NAME_REGEX],
            'last_name' => ['required', 'string', 'max:100', 'regex:'.Sanitizer::NAME_REGEX],
            'full_name' => ['required', 'string', 'max:150'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'address' => ['nullable', 'string', 'max:255'],
            // Philippine mobile number: 11 digits, 09XXXXXXXXX.
            'contact_number' => ['nullable', 'regex:/^09\d{9}$/'],
            'email' => ['nullable', 'email', 'max:100', 'unique:accounts,email'],
            // A minor comes with a parent or guardian.
            'guardian_full_name' => [Rule::requiredIf($minor), 'nullable', 'string', 'max:150'],
            'guardian_relationship' => [Rule::requiredIf($minor), 'nullable', 'string', 'max:100'],
            'guardian_contact_number' => [Rule::requiredIf($minor), 'nullable', 'regex:/^09\d{9}$/'],
            'photo' => ['required', ...VisitorProfile::PHOTO_RULES],
            // Adults show a valid ID; a minor's birth certificate stands in for it.
            'id_type' => [Rule::requiredIf(! $minor), 'nullable', Rule::in(VisitorId::TYPES)],
            'id_number' => [Rule::requiredIf(! $minor), 'nullable', 'string', 'min:4', 'max:50', 'regex:'.Sanitizer::ID_NUMBER_REGEX],
            'government_id' => [Rule::requiredIf(! $minor), 'nullable', ...$fileRules],
            'intended_pdl_name' => ['required', 'string', 'min:2', 'max:150', 'regex:'.Sanitizer::NAME_REGEX],
            // Optional; when given it must be a PDL number on record (Records PDL profile).
            'intended_pdl_number' => ['nullable', 'string', 'max:50', Rule::exists('pdl_profiles', 'pdl_number')],
            'visit_purpose' => ['required', 'string', 'max:150'],
            'relationship_type' => ['required', Rule::in(RelationshipRequirements::registrationTypes())],
            'has_children_together' => [Rule::requiredIf($this->input('relationship_type') === 'live_in_partner'), 'nullable', 'boolean'],
            'documents' => ['nullable', 'array'],
            'documents.*' => $fileRules,
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
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->requiredDocumentKeys() as $key) {
                    if (! $this->hasFile("documents.{$key}")) {
                        $validator->errors()->add("documents.{$key}",
                            'Capture or upload the '.RelationshipRequirements::DOCUMENTS[$key][0].'.');
                    }
                }

                if (! $this->boolean('not_duplicate') && $duplicate = $this->possibleDuplicate()) {
                    $validator->errors()->add('duplicate', 'A visitor with this name and birthdate is already registered.');
                    session()->flash('duplicate_visitor_id', $duplicate->visitor_id);
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
        return [
            'first_name.required' => 'Enter the visitor\'s first name.',
            'last_name.required' => 'Enter the visitor\'s last name.',
            'first_name.regex' => 'The first name can only contain letters, spaces, periods, apostrophes and hyphens.',
            'last_name.regex' => 'The last name can only contain letters, spaces, periods, apostrophes and hyphens.',
            'visit_purpose.required' => 'Enter the purpose of the visit.',
            'intended_pdl_number.exists' => 'No PDL has this PDL ID number. Check it (e.g. PDL-2026-00007) or leave it blank.',
            'date_of_birth.before' => 'The birthdate must be in the past.',
            'email.unique' => 'This email is already used by another account.',
            'guardian_full_name.required' => 'A minor must come with a parent or guardian. Enter the guardian\'s name.',
            'guardian_relationship.required' => 'Enter the guardian\'s relationship to the visitor.',
            'guardian_contact_number.required' => 'Enter the guardian\'s contact number.',
            'guardian_contact_number.regex' => 'The guardian\'s contact number must be 11 digits, starting with 09.',
            'contact_number.regex' => 'The contact number must be 11 digits, starting with 09.',
            'photo.required' => 'Take a photo of the visitor.',
            'photo.*' => 'The photo must be a JPG, PNG or WEBP picture up to 5 MB.',
            'id_type.required' => 'Select the type of valid ID the visitor showed.',
            'id_number.required' => 'Enter the ID number.',
            'id_number.regex' => 'The ID number may only contain letters, numbers, spaces, hyphens and slashes.',
            'government_id.required' => 'Capture or upload the visitor\'s valid ID.',
            'government_id.*' => 'Upload a JPG, PNG, WEBP or PDF file up to 10 MB.',
            'intended_pdl_name.required' => 'Write the name of the PDL the visitor wants to visit.',
            'intended_pdl_name.regex' => 'The PDL name can only contain letters, spaces, periods, apostrophes and hyphens.',
            'relationship_type.*' => 'Select the visitor\'s relationship to the PDL.',
            'has_children_together.required' => 'Say whether the visitor and the PDL have children together.',
            'documents.*' => 'Upload a JPG, PNG, WEBP or PDF file up to 10 MB.',
            'consent.accepted' => 'Confirm that the visitor agreed to the Terms and Privacy Policy.',
        ];
    }
}
