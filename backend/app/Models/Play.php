<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'track_id', 'seconds', 'played_at'];

    protected function casts(): array
    {
        return ['played_at' => 'datetime'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
