<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\VisitorProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitorLookupController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->input('q', ''));

        $visitors = collect();
        if ($query !== '') {
            $visitors = VisitorProfile::query()
                ->where(fn ($q) => $q->where('full_name', 'like', "%{$query}%")->orWhere('contact_number', 'like', "%{$query}%"))
                ->with(['relationships.pdl', 'activeFlags'])
                ->take(20)
                ->get();
        }

        return view('frontdesk.visitor-lookup', compact('visitors', 'query'));
    }
}
