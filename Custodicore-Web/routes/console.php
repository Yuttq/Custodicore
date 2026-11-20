<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('visits:expire')->dailyAt('00:05');

// Visitor reminders (SendVisitReminders), Asia/Manila (APP_TIMEZONE).
Schedule::command('visits:remind confirmation')->hourly()->withoutOverlapping();
Schedule::command('visits:remind today')->dailyAt('07:00')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
