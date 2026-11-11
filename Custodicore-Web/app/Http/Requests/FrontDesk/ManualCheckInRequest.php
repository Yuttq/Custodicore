<?php

namespace App\Http\Requests\FrontDesk;

use App\Models\VisitorId;
use App\Support\Sanitizer;
use Illuminate\Validation\Rule;

/**
 * POST front-desk/checkin-checkout/{visitRequest}/check-in — manual check-in
 * when the QR pass cannot be scanned. The officer records the surrendered
 * ID type and a reason for the override.
 */
class ManualCheckInRequest extends FrontDeskRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'verification_method' => Sanitizer::text($this->input('verification_method')) ?? 'manual_override',
            'id_surrendered_type' => VisitorId::resolveType(Sanitizer::text($this->input('id_surrendered_type'))),
            'override_reason' => Sanitizer::text($this->input('override_reason')),
        ]);
    }

    public function rules(): array
    {
        return [
            'verification_method' => ['required', Rule::in(['manual_override'])],
            'id_surrendered_type' => ['required', Rule::in(VisitorId::TYPES)],
            'override_reason' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'verification_method.*' => 'Invalid check-in method. Reload the page and try again.',
            'id_surrendered_type.required' => 'Select the type of ID the visitor surrendered.',
            'id_surrendered_type.in' => 'Select a valid ID type from the list.',
            'override_reason.required' => 'Give the reason for the manual check-in.',
            'override_reason.min' => 'Describe the reason for the manual check-in in at least 10 characters.',
            'override_reason.max' => 'Keep the reason under 255 characters.',
        ];
    }
}
