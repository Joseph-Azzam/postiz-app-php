<?php

namespace App\Console\Commands;

use App\Models\NotificationEvent;
use App\Support\PostizLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cron-safe digest email processor.
 *
 * Run via system cron (e.g. every 15 minutes):
 *   php artisan postiz:process-digests
 *
 * Behaviour:
 * - Find undigested events older than a configurable "age" window
 * - Group by user + channel
 * - Send one email per group (placeholder template for now)
 * - Mark events as digested with a batch id
 */
class ProcessNotificationDigests extends Command
{
    protected $signature = 'postiz:process-digests {--limit=50}';
    protected $description = 'Send batched digest emails from notification events.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit') ?: 50;
        $limit = max(1, min($limit, 200));

        $cutoffMinutes = (int) config('postiz.digest_minutes', 60);
        $cutoff = now()->subMinutes($cutoffMinutes);

        PostizLogger::event('digest.run.start', [
            'entity' => 'digest',
            'command' => $this->getName(),
            'limit' => $limit,
            'cutoff' => $cutoff->toISOString(),
        ]);

        $query = NotificationEvent::query()
            ->whereNull('digested_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at', 'asc')
            ->limit($limit);

        $events = $query->get();
        if ($events->isEmpty()) {
            PostizLogger::event('digest.run.end', [
                'entity' => 'digest',
                'command' => $this->getName(),
                'batches' => 0,
                'total_events' => 0,
            ]);
            return Command::SUCCESS;
        }

        // Group by user + channel to form digest batches.
        $grouped = $events->groupBy(function (NotificationEvent $event) {
            return ($event->user_id ?? 'none').':'.$event->channel;
        });

        $totalBatches = 0;
        $totalEvents = 0;

        foreach ($grouped as $key => $groupEvents) {
            $batchId = (string) Str::ulid();
            $totalBatches++;
            $totalEvents += $groupEvents->count();

            [$userId, $channel] = explode(':', $key, 2);

            // Debug point: email payload; real template can be plugged here.
            $summary = $groupEvents->map(function (NotificationEvent $event) {
                return [
                    'id' => $event->id,
                    'type' => $event->type,
                    'payload' => $event->payload,
                ];
            })->all();

            try {
                // Placeholder email send – does not rely on queues.
                // You can replace this with a proper Mailable later.
                if ($userId !== 'none') {
                    // In a real app you'd resolve the user's email here.
                    // For now we just log instead of attempting a real send.
                    PostizLogger::event('digest.email.placeholder', [
                        'entity' => 'digest',
                        'batch_id' => $batchId,
                        'user_id' => $userId,
                        'channel' => $channel,
                        'events' => $summary,
                    ]);
                }

                // Mark events as digested.
                NotificationEvent::query()
                    ->whereIn('id', $groupEvents->pluck('id'))
                    ->update([
                        'digest_batch_id' => $batchId,
                        'digested_at' => now(),
                    ]);

                PostizLogger::event('digest.batch.sent', [
                    'entity' => 'digest',
                    'batch_id' => $batchId,
                    'user_id' => $userId === 'none' ? null : (int) $userId,
                    'channel' => $channel,
                    'email_count' => 1,
                    'event_count' => $groupEvents->count(),
                ]);
            } catch (Throwable $e) {
                PostizLogger::event('digest.batch.error', [
                    'entity' => 'digest',
                    'batch_id' => $batchId,
                    'user_id' => $userId === 'none' ? null : (int) $userId,
                    'channel' => $channel,
                    'event_count' => $groupEvents->count(),
                    'error' => [
                        'message' => $e->getMessage(),
                        'type' => get_class($e),
                    ],
                ], level: 'error');
            }
        }

        PostizLogger::event('digest.run.end', [
            'entity' => 'digest',
            'command' => $this->getName(),
            'batches' => $totalBatches,
            'total_events' => $totalEvents,
        ]);

        return Command::SUCCESS;
    }
}

