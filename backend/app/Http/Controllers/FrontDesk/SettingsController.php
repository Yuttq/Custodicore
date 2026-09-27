<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;

class SettingsController extends Controller
{
    public function index()
    {
        return view('frontdesk.settings');
    }
}