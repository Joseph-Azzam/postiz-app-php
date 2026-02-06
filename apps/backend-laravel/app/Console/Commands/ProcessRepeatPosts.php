<?php

namespace App\Console\Commands;

use App\Models\PostRepeatRule;
use App\Models\ScheduledPost;
use App\Support\PostizLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cron-safe processor for repeat rules.
 *
 * Run via system cron, e.g. every 5 minutes:
 *   php artisan postiz:process-repeat-posts
 *
 * Behaviour:
 * - For each due active rule:
 *   - find one "template" ScheduledPost for the group
 *   - clone its payload to a new ScheduledPost scheduled at `next_run_at`
 *   - advance `executed_count` and `next_run_at`
 */
class ProcessRepeatPosts extends Command
{
    protected $signature = 'postiz:process-repeat-posts {--batch=20}';
    protected $description = 'Process repeat rules and schedule repeated posts.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch') ?: 20;
        $batchSize = max(1, min($batchSize, 100));

        PostizLogger::event('cron.repeat.run.start', [
            'entity' => 'cron',
            'command' => $this->getName(),
            'batch_size' => $batchSize,
        ]);

        $now = now()->utc();
        $processed = 0;

        $rules = PostRepeatRule::query()
            ->where('status', PostRepeatRule::STATUS_ACTIVE)
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at', 'asc')
            ->limit($batchSize)
            ->get();

        foreach ($rules as $rule) {
            $processed++;
            $this->processRule($rule);
        }

        PostizLogger::event('cron.repeat.run.end', [
            'entity' => 'cron',
            'command' => $this->getName(),
            'processed' => $processed,
        ]);

        return Command::SUCCESS;
    }

    private function processRule(PostRepeatRule $rule): void
    {
        try {
            DB::transaction(function () use ($rule) {
                $rule->refresh();

                // Re-check inside transaction to avoid race conditions.
                if ($rule->status !== PostRepeatRule::STATUS_ACTIVE) {
                    return;
                }

                $now = now()->utc();
                if ($rule->next_run_at?->gt($now)) {
                    return;
                }

                if ($rule->max_repeats !== null && $rule->executed_count >= $rule->max_repeats) {
                    $rule->status = PostRepeatRule::STATUS_COMPLETED;
                    $rule->save();
                    return;
                }

                /** @var ScheduledPost|null $template */
                $template = ScheduledPost::query()
                    ->where('group', $rule->group)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if (!$template) {
                    // Nothing to repeat; mark completed to avoid spinning forever.
                    $rule->status = PostRepeatRule::STATUS_COMPLETED;
                    $rule->save();
                    return;
                }

                $newGroup = (string) Str::ulid();
                $scheduledAt = $rule->next_run_at->clone();

                $payload = $template->payload ?? [];
                // Adjust payload date + group to keep UI behaviour consistent.
                if (is_array($payload)) {
                    $payload['type'] = 'schedule';
                    $payload['date'] = $scheduledAt->format('Y-m-d\TH:i:s');
                    if (!empty($payload['posts']) && is_array($payload['posts'])) {
                        foreach ($payload['posts'] as &$postItem) {
                            if (is_array($postItem)) {
                                $postItem['group'] = $newGroup;
                            }
                        }
                    }
                }

                $contentPreview = $template->content_preview;

                $new = ScheduledPost::create([
                    'group' => $newGroup,
                    'status' => ScheduledPost::STATUS_SCHEDULED,
                    'scheduled_at' => $scheduledAt,
                    'attempts' => 0,
                    'max_attempts' => $template->max_attempts,
                    'payload' => $payload,
                    'content_preview' => $contentPreview,
                ]);

                PostizLogger::event('repeat.post.created', [
                    'entity' => 'repeat',
                    'rule_id' => $rule->id,
                    'source_group' => $rule->group,
                    'new_post_id' => $new->id,
                    'new_group' => $newGroup,
                    'scheduled_at' => $scheduledAt->toISOString(),
                ]);

                $rule->executed_count++;
                $rule->last_run_at = $scheduledAt;

                if ($rule->max_repeats !== null && $rule->executed_count >= $rule->max_repeats) {
                    $rule->status = PostRepeatRule::STATUS_COMPLETED;
                } else {
                    $rule->next_run_at = $scheduledAt->clone()->addDays($rule->interval_days);
                }

                $rule->save();
            });
        } catch (Throwable $e) {
            PostizLogger::event('repeat.post.error', [
                'entity' => 'repeat',
                'rule_id' => $rule->id,
                'group' => $rule->group,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                ],
            ], level: 'error');
        }
    }
}

