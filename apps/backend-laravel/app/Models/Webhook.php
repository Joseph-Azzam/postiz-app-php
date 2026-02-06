<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Webhook extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['user_id', 'name', 'url', 'integration_ids'];

    protected $casts = ['integration_ids' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
