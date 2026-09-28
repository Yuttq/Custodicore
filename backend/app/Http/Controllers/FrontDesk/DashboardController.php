<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        return view('frontdesk.dashboard');
    }
}