<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Centralized structured logging for migration visibility.
 *
 * Rules (from `.cursorrules`):
 * - JSON-friendly
 * - Toggleable via env
 * - When debug enabled: log every cron run + state transition
 * - When debug disabled: log only critical errors
 *
 * NOTE: This is intentionally stateless and safe on shared hosting.
 */
final class PostizLogger
{
    public static function debugEnabled(): bool
    {
        return (bool) config('postiz.debug', false);
    }

    /**
     * Log an event with consistent keys.
     *
     * @param  array<string, mixed>  $context
     */
    public static function event(string $action, array $context = [], string $level = 'info'): void
    {
        $payload = array_merge([
            'timestamp' => now()->toISOString(),
            'action' => $action,
        ], $context);

        // When debug is off, only allow warnings/errors through (critical visibility).
        if (!self::debugEnabled() && !in_array($level, ['warning', 'error', 'critical'], true)) {
            return;
        }

        Log::channel('postiz')->{$level}($payload);
    }

    /**
     * Convenience helper for post state transitions.
     */
    public static function postTransition(string $postId, string $from, string $to, array $context = []): void
    {
        self::event('post.transition', array_merge([
            'entity' => 'post',
            'post_id' => $postId,
            'from' => $from,
            'to' => $to,
        ], $context));
    }
}

