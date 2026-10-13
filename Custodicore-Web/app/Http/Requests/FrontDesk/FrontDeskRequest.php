<?php

namespace App\Http\Requests\FrontDesk;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for Front Desk gate requests. Input is cleaned (Sanitizer) before it
 * is validated. A failed JSON request (scanner, QR check-in) answers in the
 * shape frontdesk-checkin.js reads: { success: false, message, errors },
 * with the first problem as the message. Form posts redirect back with the
 * errors as usual.
 */
abstract class FrontDeskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 'auth' + 'role:Front Desk Officer' already guard the route group
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
