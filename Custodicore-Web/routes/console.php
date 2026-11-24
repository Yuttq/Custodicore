<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Restrictions past their end date are lifted at midnight (LiftEndedRestrictions).
Schedule::command('restrictions:lift-ended')->dailyAt('00:00')->withoutOverlapping();

Schedule::command('visits:expire')->dailyAt('00:05');

// Visitor reminders (SendVisitReminders), Asia/Manila (APP_TIMEZONE).
Schedule::command('visits:remind confirmation')->hourly()->withoutOverlapping();
Schedule::command('visits:remind today')->dailyAt('07:00')->withoutOverlapping();

// Accounts returned for correction: one reminder after 7 days, closed after 45 (ReturnedAccounts).
Schedule::command('visitors:returned-follow-up')->dailyAt('08:00')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
