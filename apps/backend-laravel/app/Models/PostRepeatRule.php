<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * PostRepeatRule describes a simple "repeat every N days" rule
 * for a given ScheduledPost group.
 *
 * Cron will:
 * - look at due `next_run_at`
 * - duplicate the original group's payload into a new ScheduledPost
 * - advance `next_run_at` and `executed_count`
 */
class PostRepeatRule extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    protected $table = 'post_repeat_rules';

    protected $fillable = [
        'group',
        'interval_days',
        'executed_count',
        'max_repeats',
        'next_run_at',
        'last_run_at',
        'status',
        'payload',
    ];

    protected $casts = [
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'payload' => 'array',
    ];
}

