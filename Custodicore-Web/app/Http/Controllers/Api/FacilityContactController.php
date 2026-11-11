<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FacilityContact;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/facility-contact — the contact the app's Help Center shows.
 * `contact` is {name, phone, hours} (name/hours may be null), or null while
 * the Admin has not set a phone number on Settings.
 */
class FacilityContactController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['contact' => FacilityContact::forApp()]);
    }
}
