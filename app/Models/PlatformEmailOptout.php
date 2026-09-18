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
    protected $fillable   = ['email', 'kind', 'reason', 'source', 'detail']; // MARKER-PLATFORM-SENDLOG

    /**
     * MARKER-PLATFORM-SENDLOG — one list, three reasons.
     *
     * An address lands here because the person asked (unsubscribe), because
     * their mail server refused it (bounce), or because they marked it as spam
     * (complaint). Keeping them together means one place answers "why didn't
     * they get it", and one check at send time covers all three.
     */
    public const KINDS = [
        'unsubscribe' => 'Unsubscribed',
        'bounce'      => 'Bounced',
        'complaint'   => 'Marked as spam',
    ];

    /** Suppress an address. Never downgrades an existing, stronger reason. */
    public static function suppress(string $email, string $kind, ?string $detail = null, ?string $source = null): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $existing = static::find($email);

        // A complaint outranks a bounce outranks an unsubscribe: a later soft
        // reason must not overwrite the record of a harder one.
        $rank = ['unsubscribe' => 1, 'bounce' => 2, 'complaint' => 3];
        if ($existing && ($rank[$existing->kind] ?? 0) > ($rank[$kind] ?? 0)) {
            return;
        }

        static::updateOrCreate(
            ['email' => $email],
            ['kind' => $kind, 'reason' => $kind, 'source' => $source, 'detail' => $detail]
        );
    }

    public static function has(string $email): bool
    {
        return static::whereKey(mb_strtolower(trim($email)))->exists();
    }
}
