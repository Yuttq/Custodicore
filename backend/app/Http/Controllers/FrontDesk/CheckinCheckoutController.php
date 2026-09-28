<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;

class CheckinCheckoutController extends Controller
{
    public function index()
    {
        return view('frontdesk.checkin-checkout');
    }
}