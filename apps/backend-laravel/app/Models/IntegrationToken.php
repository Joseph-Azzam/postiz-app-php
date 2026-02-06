<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Integration (channel) per user; mirrors NestJS Integration.
 * Stores OAuth tokens in payload; display fields for list.
 */
class IntegrationToken extends Model
{
    use HasUlids;

    protected $table = 'integration_tokens';

    protected $fillable = [
        'user_id',
        'provider',
        'account_identifier',
        'payload',
        'refresh_token',
        'posting_times',
        'name',
        'picture',
        'profile',
        'group',
        'expires_at',
        'last_refreshed_at',
        'status',
        'disabled',
        'in_between_steps',
        'additional_settings',
    ];

    protected $casts = [
        'payload' => 'array',
        'posting_times' => 'array',
        'expires_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'disabled' => 'boolean',
        'in_between_steps' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

