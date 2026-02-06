<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tag for posts; scoped per user. Used by TagsComponent (posts/tags API).
 */
class Tag extends Model
{
    use HasUlids;

    protected $table = 'tags';

    protected $fillable = [
        'user_id',
        'name',
        'color',
    ];
}
