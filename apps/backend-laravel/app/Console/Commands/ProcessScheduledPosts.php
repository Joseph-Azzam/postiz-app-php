<?php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Services\PublishToSocialService;
use App\Support\PostizLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cron-safe processor for scheduled posts.
 *
 * Run every minute via system cron:
 *   php artisan postiz:process-scheduled-posts
 *
 * Constraints (from `.cursorrules`):
 * - No workers/queues
 * - Stateless execution
 * - Small batch size
 * - Idempotent + resumable
 * - Explicit state transitions with logging
 */
class ProcessScheduledPosts extends Command
{
    protected $signature = 'postiz:process-scheduled-posts {--batch= : Override batch size}';
    protected $description = 'Process due scheduled posts (cron-only).';

    public function handle(): int
    {
        $batchSize = (int) ($this->option('batch') ?: config('postiz.cron_batch_size', 10));
        $batchSize = max(1, min($batchSize, 50)); // hard safety cap

        PostizLogger::event('cron.run.start', [
            'entity' => 'cron',
            'command' => $this->getName(),
            'batch_size' => $batchSize,
        ]);

        $now = now()->utc();
        $processed = 0;

        // Select due posts (the `.cursorrules` query pattern).
        $duePosts = ScheduledPost::query()
            ->whereIn('status', [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_RETRYING])
            ->where('scheduled_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $now);
            })
            ->whereColumn('attempts', '<', 'max_attempts')
            ->orderBy('scheduled_at', 'asc')
            ->limit($batchSize)
            ->get();

        foreach ($duePosts as $post) {
            $claimed = $this->claimForProcessing($post->id);
            if (!$claimed) {
                // Another cron run/process claimed it first.
                continue;
            }

            $processed++;
            $this->processOne($post->id);
        }

        PostizLogger::event('cron.run.end', [
            'entity' => 'cron',
            'command' => $this->getName(),
            'processed' => $processed,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Atomically claim a post by transitioning to `processing`.
     * This prevents double-publishing when cron overlaps.
     */
    private function claimForProcessing(string $id): bool
    {
        $now = now()->utc();

        return DB::transaction(function () use ($id, $now) {
            /** @var ScheduledPost|null $post */
            $post = ScheduledPost::lockForUpdate()->find($id);
            if (!$post) {
                return false;
            }

            if (!in_array($post->status, [ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_RETRYING], true)) {
                return false;
            }

            if ($post->scheduled_at?->gt($now)) {
                return false;
            }

            if ($post->next_retry_at && $post->next_retry_at->gt($now)) {
                return false;
            }

            if ($post->attempts >= $post->max_attempts) {
                return false;
            }

            $from = $post->status;
            $post->status = ScheduledPost::STATUS_PROCESSING;
            $post->save();

            PostizLogger::postTransition($post->id, $from, $post->status, [
                'scheduled_at' => $post->scheduled_at?->toISOString(),
                'attempts' => $post->attempts,
            ]);

            return true;
        });
    }

    /**
     * Perform the side-effect (publishing) and transition states.
     *
     * For this incremental migration step we do NOT call external APIs yet.
     * Instead we:
     * - log the intention to publish (debug)
     * - mark the post as published
     *
     * This keeps the state machine + cron reliability in place.
     */
    private function processOne(string $id): void
    {
        /** @var ScheduledPost $post */
        $post = ScheduledPost::findOrFail($id);

        try {
            PostizLogger::event('post.publish.attempt', [
                'entity' => 'post',
                'post_id' => $post->id,
                'group' => $post->group,
                'attempts' => $post->attempts,
                'scheduled_at' => $post->scheduled_at?->toISOString(),
            ]);

            $post->attempts = $post->attempts + 1;
            $post->save();

            $service = app(PublishToSocialService::class);
            $error = $service->publish($post);

            if ($error !== null) {
                throw new \RuntimeException($error);
            }

            $from = $post->status;
            $post->status = ScheduledPost::STATUS_PUBLISHED;
            $post->published_at = now()->utc();
            $post->last_error = null;
            $post->next_retry_at = null;
            $post->save();

            PostizLogger::postTransition($post->id, $from, $post->status, [
                'published_at' => $post->published_at?->toISOString(),
                'attempts' => $post->attempts,
                'result' => 'ok',
            ]);
        } catch (Throwable $e) {
            $this->markRetryOrFail($post, $e);
        }
    }

    private function markRetryOrFail(ScheduledPost $post, Throwable $e): void
    {
        $from = $post->status;
        $post->last_error = $e->getMessage();

        $willRetry = ($post->attempts < $post->max_attempts);
        if ($willRetry) {
            $post->status = ScheduledPost::STATUS_RETRYING;
            $post->next_retry_at = now()->utc()->addMinutes((int) config('postiz.retry_backoff_minutes', 5));
        } else {
            $post->status = ScheduledPost::STATUS_FAILED;
        }

        $post->save();

        // Critical visibility even when debug is off.
        PostizLogger::postTransition($post->id, $from, $post->status, [
            'attempts' => $post->attempts,
            'max_attempts' => $post->max_attempts,
            'next_retry_at' => $post->next_retry_at?->toISOString(),
            'error' => [
                'message' => $e->getMessage(),
                'type' => get_class($e),
            ],
        ]);

        PostizLogger::event('post.publish.error', [
            'entity' => 'post',
            'post_id' => $post->id,
            'group' => $post->group,
            'result' => 'error',
            'error' => [
                'message' => $e->getMessage(),
                'type' => get_class($e),
            ],
        ], level: 'error');
    }
}

