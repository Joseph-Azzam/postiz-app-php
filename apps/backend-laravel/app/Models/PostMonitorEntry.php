<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks monitoring status for a ScheduledPost.
 *
 * Real "missing" detection would involve provider APIs; we just implement
 * a placeholder that can mark posts as "checked" and optionally retried.
 */
class PostMonitorEntry extends Model
{
    protected $table = 'post_monitor_entries';

    protected $fillable = [
        'scheduled_post_id',
        'status',
        'last_checked_at',
        'last_action',
        'last_error',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
    ];
}

