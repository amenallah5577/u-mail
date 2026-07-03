<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mail:purge-trash')->dailyAt('02:00');
Schedule::command('mail:send-scheduled')->everyMinute();
Schedule::call(fn () => DB::table('security_events')->where('created_at', '<', now()->subDays(config('security.audit_retention_days')))->delete())
    ->dailyAt('02:30')
    ->name('security-events:purge');
