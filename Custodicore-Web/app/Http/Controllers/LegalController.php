<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Public Terms & Conditions / Privacy Policy pages (no login — people must be
 * able to read them BEFORE they register) plus the JSON the mobile app shows
 * in its consent step. All wording comes from config/legal.php.
 */
class LegalController extends Controller
{
    public function terms(): View
    {
        return $this->page('terms', 'privacy');
    }

    public function privacy(): View
    {
        return $this->page('privacy', 'terms');
    }

    /** GET /api/legal — same text as the web pages, for the mobile consent step. */
    public function api(): JsonResponse
    {
        return response()->json([
            'version' => config('legal.version'),
            'effectiveDate' => config('legal.effective_date'),
            'draft' => (bool) config('legal.draft'),
            'terms' => config('legal.terms'),
            'privacy' => config('legal.privacy'),
        ]);
    }

    private function page(string $key, string $otherKey): View
    {
        return view('legal.show', [
            'doc' => config("legal.{$key}"),
            'other' => config("legal.{$otherKey}"),
            'otherRoute' => $otherKey === 'terms' ? 'legal.terms' : 'legal.privacy',
            'version' => config('legal.version'),
            'effective' => config('legal.effective_date'),
            'draft' => (bool) config('legal.draft'),
        ]);
    }
}
