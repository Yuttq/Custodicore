<?php

namespace App\Http\Requests\Admin;

use App\Models\StaffProfile;
use App\Support\Sanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Register Officer (POST admin/users) and Edit Officer (PATCH admin/users/{id}).
 *
 * Input is cleaned first (Sanitizer), then validated. Duplicates are refused
 * at three levels so one real person cannot end up with two accounts: the
 * email, the mobile number, and the exact same name.
 *
 * There is no separate "official email" any more: `personal_email` is the
 * officer's own email. It is where the setup link is sent AND the address they
 * sign in with (UserController copies it to accounts.email). They can also sign
 * in with the unique username they choose on the setup page.
 */
class StaffAccountRequest extends FormRequest
{
    public const ROLE_NAMES = ['System Administrator/Warden', 'Record Officer', 'Front Desk Officer'];

    public function authorize(): bool
    {
        return true; // 'auth' + 'role:System Administrator/Warden' already guard the route group
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'last_name' => Sanitizer::name($this->input('last_name')),
            'first_name' => Sanitizer::name($this->input('first_name')),
            'middle_name' => Sanitizer::name($this->input('middle_name')),
            'suffix' => Sanitizer::suffix($this->input('suffix')),
            'role_name' => Sanitizer::text($this->input('role_name')),
            'personal_email' => Sanitizer::email($this->input('personal_email')),
            'contact_number' => Sanitizer::mobile($this->input('contact_number')),
        ]);
    }

    public function rules(): array
    {
        $accountId = $this->route('id') ? (int) $this->route('id') : null;
        $staffId = $accountId ? StaffProfile::where('account_id', $accountId)->value('staff_id') : null;

        return [
            'last_name' => [
                'required', 'string', 'min:2', 'max:100', 'regex:' . Sanitizer::NAME_REGEX,
                // Same first + middle + last + suffix = same person.
                function (string $attribute, $value, \Closure $fail) use ($staffId) {
                    $lower = fn ($v) => mb_strtolower((string) $v);

                    $exists = StaffProfile::query()
                        ->whereRaw('LOWER(first_name) = ?', [$lower($this->input('first_name'))])
                        ->whereRaw('LOWER(last_name) = ?', [$lower($value)])
                        ->whereRaw("LOWER(COALESCE(middle_name, '')) = ?", [$lower($this->input('middle_name'))])
                        ->whereRaw("LOWER(COALESCE(suffix, '')) = ?", [$lower($this->input('suffix'))])
                        ->when($staffId, fn ($q) => $q->where('staff_id', '!=', $staffId))
                        ->exists();

                    if ($exists) {
                        $fail('A staff member with this exact name already exists. If it is a different person, add a middle name or suffix.');
                    }
                },
            ],
            'first_name' => ['required', 'string', 'min:2', 'max:100', 'regex:' . Sanitizer::NAME_REGEX],
            'middle_name' => ['nullable', 'string', 'max:100', 'regex:' . Sanitizer::NAME_REGEX],
            'suffix' => ['nullable', Rule::in(Sanitizer::SUFFIXES)],
            'role_name' => ['required', Rule::in(self::ROLE_NAMES)],
            'contact_number' => [
                'required', 'regex:' . Sanitizer::MOBILE_REGEX,
                Rule::unique('staff_profiles', 'contact_number')->ignore($staffId, 'staff_id'),
            ],
            // The officer's own email (e.g. Gmail): the setup link goes here and it is
            // also their sign-in email. Required for a new officer; optional when
            // editing an older account (blank = keep what the account has now).
            // Must not already be anybody's sign-in email or staff personal email.
            'personal_email' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'email:rfc', 'max:100',
                Rule::unique('staff_profiles', 'personal_email')->ignore($staffId, 'staff_id'),
                Rule::unique('accounts', 'email')->ignore($accountId, 'account_id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'last_name.regex' => 'The last name may only contain letters, spaces, dots, apostrophes and hyphens.',
            'first_name.regex' => 'The first name may only contain letters, spaces, dots, apostrophes and hyphens.',
            'middle_name.regex' => 'The middle name may only contain letters, spaces, dots, apostrophes and hyphens.',
            'suffix.in' => 'Choose a suffix from the list (Jr., Sr., II, III, IV, V) or leave it blank.',
            'personal_email.required' => 'Enter the officer\'s email — the setup link is sent there and they sign in with it.',
            'personal_email.email' => 'Enter a valid email address, e.g. juan.delacruz@gmail.com.',
            'personal_email.unique' => 'This email is already used by another account. Use a different email.',
            'contact_number.regex' => 'Enter a valid Philippine mobile number, e.g. 0917 123 4567.',
            'contact_number.unique' => 'This mobile number already belongs to another staff member.',
        ];
    }

    public function attributes(): array
    {
        return ['contact_number' => 'mobile number', 'role_name' => 'role', 'personal_email' => 'email'];
    }
}
