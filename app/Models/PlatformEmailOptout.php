<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MARKER-PLATFORM-EMAIL — one unsubscribe stops platform mail to that address. */
class PlatformEmailOptout extends Model
{
    protected $table      = 'platform_email_optouts';
    protected $primaryKey = 'email';
    public    $incrementing = false;
    protected $keyType    = 'string';
    protected $fillable   = ['email', 'reason', 'source'];

    public static function has(string $email): bool
    {
        return static::whereKey(mb_strtolower(trim($email)))->exists();
    }
}
