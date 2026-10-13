<?php

namespace App\Http\Requests\FrontDesk;

use App\Models\VisitorId;
use App\Support\Sanitizer;
use Illuminate\Validation\Rule;

/**
 * POST front-desk/checkin-checkout/confirm — QR check-in. When the visitor
 * presents a different ID than the one registered, the new ID's type,
 * number and reason are required; its photo stays optional.
 */
class QrCheckInRequest extends FrontDeskRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'qr_token' => Sanitizer::token($this->input('qr_token')),
            'id_match_status' => Sanitizer::text($this->input('id_match_status')),
            'id_surrendered_type' => VisitorId::resolveType(Sanitizer::text($this->input('id_surrendered_type'))),
            'new_id_type' => VisitorId::resolveType(Sanitizer::text($this->input('new_id_type'))),
            'new_id_number' => Sanitizer::idNumber($this->input('new_id_number')),
            'new_id_reason' => Sanitizer::text($this->input('new_id_reason')),
        ]);
    }

    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9]+$/'],
            'id_match_status' => ['required', Rule::in(['matched', 'replaced'])],
            'id_surrendered_type' => ['required', Rule::in(VisitorId::TYPES)],
            'new_id_type' => ['nullable', 'required_if:id_match_status,replaced', Rule::in(VisitorId::TYPES)],
            'new_id_number' => ['nullable', 'required_if:id_match_status,replaced', 'string', 'min:4', 'max:50', 'regex:'.Sanitizer::ID_NUMBER_REGEX],
            'new_id_reason' => ['nullable', 'required_if:id_match_status,replaced', 'string', 'min:3', 'max:255'],
            'new_id_photo' => ['nullable', 'string', 'max:7000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'qr_token.*' => 'The QR pass could not be read. Scan the visitor\'s QR code again.',
            'id_match_status.required' => 'Select whether the visitor\'s ID matches the registered ID.',
            'id_match_status.in' => 'Select whether the visitor\'s ID matches the registered ID.',
            'id_surrendered_type.required' => 'Select the type of ID the visitor surrendered.',
            'id_surrendered_type.in' => 'Select a valid ID type from the list.',
            'new_id_type.required_if' => 'Select the type of the new ID.',
            'new_id_type.in' => 'Select a valid ID type from the list.',
            'new_id_number.required_if' => 'Enter the new ID number.',
            'new_id_number.min' => 'The ID number is too short. Check it against the physical ID.',
            'new_id_number.max' => 'The ID number may not be longer than 50 characters.',
            'new_id_number.regex' => 'The ID number may only contain letters, numbers, spaces, hyphens and slashes.',
            'new_id_reason.required_if' => 'Select or describe the reason for the new ID.',
            'new_id_reason.min' => 'Describe the reason for the new ID in a few words.',
            'new_id_reason.max' => 'Keep the reason under 255 characters.',
            'new_id_photo.max' => 'The ID photo is too large. Retake it or upload an image under 5 MB.',
        ];
    }
}
