<?php

namespace App\Http\Requests\FrontDesk;

use App\Support\Sanitizer;
use Illuminate\Validation\Rule;

/** POST front-desk/checkin-checkout/scan — look up a scanned QR pass. */
class ScanQrRequest extends FrontDeskRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'qr_token' => Sanitizer::token($this->input('qr_token')),
            'mode' => Sanitizer::text($this->input('mode')),
        ]);
    }

    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'min:20', 'max:255', 'regex:/^[A-Za-z0-9]+$/'],
            'mode' => ['nullable', Rule::in(['checkin', 'checkout'])],
        ];
    }

    public function messages(): array
    {
        return [
            'qr_token.required' => 'No QR code was read. Please scan the visitor\'s QR pass again.',
            'qr_token.min' => 'This is not a CustodiCore QR pass. Ask the visitor to open their pass in the app.',
            'qr_token.max' => 'This is not a CustodiCore QR pass. Ask the visitor to open their pass in the app.',
            'qr_token.regex' => 'This is not a CustodiCore QR pass. Ask the visitor to open their pass in the app.',
            'mode.in' => 'Unknown scan mode. Reload the page and try again.',
        ];
    }
}
