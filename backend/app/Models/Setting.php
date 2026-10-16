<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::query()->find($key)?->value;

        return $value === null ? $default : json_decode($value, true);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    /** Store a value encrypted with the app key (for secrets such as the Telegram api_hash). */
    public static function putSecret(string $key, ?string $value): void
    {
        static::put($key, $value === null ? null : Crypt::encryptString($value));
    }

    public static function getSecret(string $key): ?string
    {
        $value = static::get($key);

        return $value === null ? null : Crypt::decryptString($value);
    }
}
