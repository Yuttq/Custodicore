<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;

class VisitorLookupController extends Controller
{
    public function index()
    {
        return view('frontdesk.visitor-lookup');
    }
}