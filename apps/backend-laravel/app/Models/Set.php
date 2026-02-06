<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Set extends Model
{
    use HasUlids;

    protected $table = 'sets';

    protected $fillable = ['user_id', 'name', 'content'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
