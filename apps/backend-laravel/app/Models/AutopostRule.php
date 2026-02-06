<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * AutopostRule represents a recurring hourly-like autopost configuration
 * for a single account/channel.
 *
 * The actual post creation is currently a placeholder; we only log the intent.
 */
class AutopostRule extends Model
{
    use HasUlids;

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $table = 'autopost_rules';

    protected $fillable = [
        'account_identifier',
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

