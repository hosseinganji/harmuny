<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lyric extends Model
{
    protected $fillable = ['lookup_key', 'synced', 'plain', 'instrumental', 'source'];

    protected function casts(): array
    {
        return ['instrumental' => 'boolean'];
    }
}
