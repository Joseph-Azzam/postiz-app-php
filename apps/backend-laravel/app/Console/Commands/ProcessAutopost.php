<?php

namespace App\Console\Commands;

use App\Models\AutopostRule;
use App\Support\PostizLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cron-safe autopost processor.
 *
 * Run hourly via system cron:
 *   php artisan postiz:process-autopost
 *
 * For now, this only logs the autopost actions instead of calling providers.
 */
class ProcessAutopost extends Command
{
    protected $signature = 'postiz:process-autopost {--batch=50}';
    protected $description = 'Trigger autopost for enabled accounts.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch') ?: 50;
        $batchSize = max(1, min($batchSize, 200));

        PostizLogger::event('autopost.run.start', [
            'entity' => 'autopost',
            'command' => $this->getName(),
            'batch_size' => $batchSize,
        ]);

        $now = now()->utc();
        $rules = AutopostRule::query()
            ->where('status', AutopostRule::STATUS_ENABLED)
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at', 'asc')
            ->limit($batchSize)
            ->get();

        $processed = 0;

        foreach ($rules as $rule) {
            $processed++;
            $this->processRule($rule);
        }

        PostizLogger::event('autopost.run.end', [
            'entity' => 'autopost',
            'command' => $this->getName(),
            'processed' => $processed,
        ]);

        return Command::SUCCESS;
    }

    private function processRule(AutopostRule $rule): void
    {
        try {
            DB::transaction(function () use ($rule) {
                $rule->refresh();

                if ($rule->status !== AutopostRule::STATUS_ENABLED) {
                    return;
                }

                $now = now()->utc();
                if ($rule->next_run_at?->gt($now)) {
                    return;
                }

                // Debug point: this is where we'd select content and create posts.
                PostizLogger::event('autopost.account.run', [
                    'entity' => 'autopost',
                    'rule_id' => $rule->id,
                    'account_identifier' => $rule->account_identifier,
                    'payload' => $rule->payload,
                ]);

                $rule->last_run_at = $now;
                // Next run in 1 hour by default.
                $rule->next_run_at = $now->clone()->addHour();
                $rule->save();
            });
        } catch (Throwable $e) {
            PostizLogger::event('autopost.account.error', [
                'entity' => 'autopost',
                'rule_id' => $rule->id,
                'account_identifier' => $rule->account_identifier,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ],
            ], level: 'error');
        }
    }
}

