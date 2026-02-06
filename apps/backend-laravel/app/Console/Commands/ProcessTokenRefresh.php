<?php

namespace App\Console\Commands;

use App\Models\IntegrationToken;
use App\Support\PostizLogger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cron-safe token refresh checker.
 *
 * Run every N minutes via system cron:
 *   php artisan postiz:process-tokens
 *
 * Behaviour:
 * - Find active tokens expiring within a configurable window
 * - "Refresh" them by extending the expiry (placeholder)
 * - Log each refresh attempt with success/failure
 */
class ProcessTokenRefresh extends Command
{
    protected $signature = 'postiz:process-tokens {--limit=50}';
    protected $description = 'Refresh expiring integration tokens.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit') ?: 50;
        $limit = max(1, min($limit, 200));

        $windowMinutes = (int) config('postiz.token_refresh_window', 60);
        $now = now()->utc();
        $windowEnd = $now->clone()->addMinutes($windowMinutes);

        PostizLogger::event('token.run.start', [
            'entity' => 'token',
            'command' => $this->getName(),
            'limit' => $limit,
            'window_end' => $windowEnd->toISOString(),
        ]);

        $tokens = IntegrationToken::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $windowEnd])
            ->orderBy('expires_at', 'asc')
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($tokens as $token) {
            $processed++;
            $this->refreshOne($token);
        }

        PostizLogger::event('token.run.end', [
            'entity' => 'token',
            'command' => $this->getName(),
            'processed' => $processed,
        ]);

        return Command::SUCCESS;
    }

    private function refreshOne(IntegrationToken $token): void
    {
        try {
            // Debug point: where provider-specific refresh would be called.
            PostizLogger::event('token.refresh.attempt', [
                'entity' => 'token',
                'token_id' => $token->id,
                'provider' => $token->provider,
                'account_identifier' => $token->account_identifier,
                'expires_at' => $token->expires_at?->toISOString(),
            ]);

            // Placeholder "refresh": extend by 1 hour.
            $token->last_refreshed_at = now()->utc();
            $token->expires_at = $token->expires_at?->clone()->addHour();
            $token->save();

            PostizLogger::event('token.refresh.success', [
                'entity' => 'token',
                'token_id' => $token->id,
                'provider' => $token->provider,
                'account_identifier' => $token->account_identifier,
                'new_expires_at' => $token->expires_at?->toISOString(),
            ]);
        } catch (Throwable $e) {
            PostizLogger::event('token.refresh.error', [
                'entity' => 'token',
                'token_id' => $token->id,
                'provider' => $token->provider,
                'account_identifier' => $token->account_identifier,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ],
            ], level: 'error');
        }
    }
}

