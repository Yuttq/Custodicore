<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;

class ScheduleController extends Controller
{
    public function index()
    {
        return view('frontdesk.schedule');
    }
}