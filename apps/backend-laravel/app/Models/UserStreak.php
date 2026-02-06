<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * UserStreak stores basic streak info for reminder emails.
 */
class UserStreak extends Model
{
    protected $table = 'user_streaks';

    protected $fillable = [
        'user_id',
        'current_streak_days',
        'last_post_at',
        'last_reminder_at',
        'status',
    ];

    protected $casts = [
        'last_post_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];
}

