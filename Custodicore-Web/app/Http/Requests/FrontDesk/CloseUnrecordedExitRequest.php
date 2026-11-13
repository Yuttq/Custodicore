<?php

namespace App\Http\Requests\FrontDesk;

use App\Support\Sanitizer;
use Illuminate\Validation\Rule;

/**
 * POST front-desk/checkin-checkout/{checkin}/close-unrecorded — "Close
 * record – exit not recorded". The officer's password is checked first by
 * the `reauth` middleware; this validates the explanation and where the
 * visitor's surrendered ID is.
 */
class CloseUnrecordedExitRequest extends FrontDeskRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => Sanitizer::text($this->input('reason')),
            'id_at_desk' => Sanitizer::text($this->input('id_at_desk')),
        ]);
    }

    public function rules(): array
    {
        return [
            // 150: the audit entry (255 characters) also holds the visit and ID answer.
            'reason' => ['required', 'string', 'min:10', 'max:150'],
            'id_at_desk' => ['required', Rule::in(['yes', 'no'])],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Explain why this record is being closed without a check-out.',
            'reason.min' => 'Explain why this record is being closed in at least 10 characters.',
            'reason.max' => 'Keep the explanation under 150 characters.',
            'id_at_desk.required' => 'Say whether the visitor\'s surrendered ID is still at the desk.',
            'id_at_desk.in' => 'Say whether the visitor\'s surrendered ID is still at the desk.',
        ];
    }
}
