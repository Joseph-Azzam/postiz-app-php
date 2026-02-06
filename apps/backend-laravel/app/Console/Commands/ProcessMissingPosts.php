<?php

namespace App\Console\Commands;

use App\Models\PostMonitorEntry;
use App\Models\ScheduledPost;
use App\Support\PostizLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cron-safe "missing posts" monitor.
 *
 * For now, we do NOT call provider APIs. Instead, we:
 * - periodically mark published posts as "checked"
 * - if a post is still in a non-final state for too long, we can mark it failed
 *   or decide to retry (placeholder).
 */
class ProcessMissingPosts extends Command
{
    protected $signature = 'postiz:process-missing-posts {--limit=50}';
    protected $description = 'Monitor posts for potential missing/failed deliveries.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit') ?: 50;
        $limit = max(1, min($limit, 200));

        PostizLogger::event('missing.run.start', [
            'entity' => 'missing',
            'command' => $this->getName(),
            'limit' => $limit,
        ]);

        // Select posts that are published but never monitored, or in processing for too long.
        $now = now()->utc();
        $staleProcessingCutoff = $now->clone()->subMinutes(30);

        $candidates = ScheduledPost::query()
            ->where(function ($q) use ($staleProcessingCutoff) {
                $q->where('status', ScheduledPost::STATUS_PUBLISHED)
                    ->orWhere(function ($q2) use ($staleProcessingCutoff) {
                        $q2->where('status', ScheduledPost::STATUS_PROCESSING)
                            ->where('updated_at', '<=', $staleProcessingCutoff);
                    });
            })
            ->orderBy('updated_at', 'asc')
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($candidates as $post) {
            $processed++;
            $this->checkPost($post);
        }

        PostizLogger::event('missing.run.end', [
            'entity' => 'missing',
            'command' => $this->getName(),
            'processed' => $processed,
        ]);

        return Command::SUCCESS;
    }

    private function checkPost(ScheduledPost $post): void
    {
        try {
            DB::transaction(function () use ($post) {
                $monitor = PostMonitorEntry::query()
                    ->where('scheduled_post_id', $post->id)
                    ->lockForUpdate()
                    ->first();

                if (!$monitor) {
                    $monitor = PostMonitorEntry::create([
                        'scheduled_post_id' => $post->id,
                        'status' => $post->status,
                    ]);
                }

                $monitor->last_checked_at = now()->utc();
                $monitor->status = $post->status;
                $monitor->last_action = 'checked';
                $monitor->last_error = null;
                $monitor->save();

                PostizLogger::event('missing.post.checked', [
                    'entity' => 'missing',
                    'monitor_id' => $monitor->id,
                    'post_id' => $post->id,
                    'status' => $post->status,
                ]);
            });
        } catch (Throwable $e) {
            PostizLogger::event('missing.post.error', [
                'entity' => 'missing',
                'post_id' => $post->id,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ],
            ], level: 'error');
        }
    }
}

