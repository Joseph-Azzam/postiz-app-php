<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ScheduledPost is our cron-driven state machine entity.
 *
 * It intentionally stores a raw `payload` (JSON) to keep the frontend UX stable
 * while we incrementally migrate Postiz features.
 */
class ScheduledPost extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';

    protected $table = 'scheduled_posts';

    protected $fillable = [
        'group',
        'integration_id',
        'status',
        'scheduled_at',
        'attempts',
        'max_attempts',
        'last_error',
        'next_retry_at',
        'payload',
        'content_preview',
        'published_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'published_at' => 'datetime',
        'payload' => 'array',
    ];

    /**
     * Mapping for the Postiz UI's `state` field (used in the calendar UI).
     *
     * UI checks: 'DRAFT', 'QUEUE', 'PUBLISHED', 'FAILED'
     */
    public function uiState(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'DRAFT',
            self::STATUS_PUBLISHED => 'PUBLISHED',
            self::STATUS_FAILED => 'FAILED',
            // scheduled/retrying/processing all look "queued" in UI (cron will advance them)
            default => 'QUEUE',
        };
    }
}

