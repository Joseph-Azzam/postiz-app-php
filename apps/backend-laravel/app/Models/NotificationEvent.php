<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw notification events that will later be batched into digest emails.
 */
class NotificationEvent extends Model
{
    protected $table = 'notification_events';

    protected $fillable = [
        'user_id',
        'organization_id',
        'channel',
        'type',
        'payload',
        'digest_batch_id',
        'digested_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'digested_at' => 'datetime',
    ];
}

