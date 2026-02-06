<?php

namespace App\Console\Commands;

use App\Models\UserStreak;
use App\Support\PostizLogger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cron-safe streak reminder processor.
 *
 * Run daily via system cron:
 *   php artisan postiz:process-streaks
 */
class ProcessStreakReminders extends Command
{
    protected $signature = 'postiz:process-streaks {--limit=100}';
    protected $description = 'Send streak reminder emails to users.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit') ?: 100;
        $limit = max(1, min($limit, 500));

        $reminderGapHours = (int) config('postiz.streak_reminder_hours', 24);
        $now = now()->utc();
        $reminderCutoff = $now->clone()->subHours($reminderGapHours);

        PostizLogger::event('streak.run.start', [
            'entity' => 'streak',
            'command' => $this->getName(),
            'limit' => $limit,
            'reminder_gap_hours' => $reminderGapHours,
        ]);

        $streaks = UserStreak::query()
            ->where('status', 'active')
            ->where(function ($q) use ($reminderCutoff) {
                $q->whereNull('last_reminder_at')
                    ->orWhere('last_reminder_at', '<=', $reminderCutoff);
            })
            ->orderBy('last_reminder_at', 'asc')
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($streaks as $streak) {
            $processed++;
            $this->remindOne($streak);
        }

        PostizLogger::event('streak.run.end', [
            'entity' => 'streak',
            'command' => $this->getName(),
            'processed' => $processed,
        ]);

        return Command::SUCCESS;
    }

    private function remindOne(UserStreak $streak): void
    {
        try {
            // Debug point: where actual email send would occur.
            PostizLogger::event('streak.reminder.placeholder', [
                'entity' => 'streak',
                'streak_id' => $streak->id,
                'user_id' => $streak->user_id,
                'current_streak_days' => $streak->current_streak_days,
                'last_post_at' => $streak->last_post_at?->toISOString(),
            ]);

            $streak->last_reminder_at = now()->utc();
            $streak->save();
        } catch (Throwable $e) {
            PostizLogger::event('streak.reminder.error', [
                'entity' => 'streak',
                'streak_id' => $streak->id,
                'user_id' => $streak->user_id,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ],
            ], level: 'error');
        }
    }
}

