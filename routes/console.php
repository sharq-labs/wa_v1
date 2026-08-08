<?php

use Illuminate\Support\Facades\Schedule;

// Safety net: resume delayed automation waits whose queue jobs were lost.
Schedule::command('automation:resume-due-waits')->everyMinute();

// Scheduled template synchronisation from providers.
Schedule::command('templates:sync-all')->hourly();

// Launch scheduled campaigns whose time has come (redundant with delayed jobs).
Schedule::command('campaigns:dispatch-due')->everyMinute();

// Expire platform subscriptions after their paid period ends.
Schedule::command('billing:expire-subscriptions')->hourly();

// Clean processed webhook events after the retention window.
Schedule::command('webhooks:prune')->daily();
