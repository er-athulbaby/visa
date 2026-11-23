<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
Schedule::command('files:purge')->dailyAt('02:00');
Schedule::command('db:backup')->dailyAt('01:00')->environments(['production'])
    ->onFailure(fn () => Log::critical('Nightly database backup failed'));
