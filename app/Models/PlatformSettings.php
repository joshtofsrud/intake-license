<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MARKER-PLATFORM-MAIL — single-row platform settings (id = 1), mirroring
 * the BillingSettings pattern. Editable in master admin so the platform
 * sender is not an .env deploy step.
 */
class PlatformSettings extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = [
        'mail_from_address',
        'mail_from_name',
        'email_rate',             // MARKER-EMAIL-LEDGER
        'email_broadcast_stream', // MARKER-EMAIL-LEDGER
    ];

    /** Laravel's framework default when nothing is configured. */
    public const PLACEHOLDER_ADDRESS = 'hello@example.com';

    /** Per-request memo so a batch send does not re-query for every message. */
    protected static ?self $memo = null;

    public static function current(): self
    {
        if (static::$memo) {
            return static::$memo;
        }

        $row = self::find(1);
        if (! $row) {
            $row = self::create(['id' => 1]);
        }

        return static::$memo = $row;
    }

    /** Forget the memo (used after saving from master admin). */
    public static function forget(): void
    {
        static::$memo = null;
    }

    /**
     * The effective sender address: stored setting, else env/config, else
     * null when the only thing available is the framework placeholder.
     */
    public static function fromAddress(): ?string
    {
        $stored = trim((string) (self::current()->mail_from_address ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $config = trim((string) config('mail.from.address'));
        return ($config !== '' && strcasecmp($config, self::PLACEHOLDER_ADDRESS) !== 0)
            ? $config
            : null;
    }

    public static function fromName(): ?string
    {
        $stored = trim((string) (self::current()->mail_from_name ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $config = trim((string) config('mail.from.name'));
        return ($config !== '' && $config !== 'Example') ? $config : null;
    }

    /** True when mail would still go out as the framework placeholder. */
    public static function isPlaceholder(): bool
    {
        return self::fromAddress() === null;
    }
}
