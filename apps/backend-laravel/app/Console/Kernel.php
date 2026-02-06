<?php

namespace App\Console;

use App\Console\Commands\ProcessScheduledPosts;
use App\Console\Commands\ProcessRepeatPosts;
use App\Console\Commands\ProcessNotificationDigests;
use App\Console\Commands\ProcessAutopost;
use App\Console\Commands\ProcessTokenRefresh;
use App\Console\Commands\ProcessMissingPosts;
use App\Console\Commands\ProcessStreakReminders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Console Kernel
 *
 * We DO NOT schedule long-running jobs here.
 * System cron is the worker (per `.cursorrules`).
 *
 * This file only registers commands so `php artisan ...` works on shared hosting.
 */
class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        ProcessScheduledPosts::class,
        ProcessRepeatPosts::class,
        ProcessNotificationDigests::class,
        ProcessAutopost::class,
        ProcessTokenRefresh::class,
        ProcessMissingPosts::class,
        ProcessStreakReminders::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * NOTE: Keep empty. Use system cron to run commands every minute.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Intentionally empty (cron is configured outside Laravel).
    }
}

