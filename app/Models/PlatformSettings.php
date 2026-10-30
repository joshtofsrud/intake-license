<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * single-row platform settings (id = 1), mirroring
 * the BillingSettings pattern. Editable in master admin so the platform
 * sender is not an .env deploy step.
 */
class PlatformSettings extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = [
        'mail_from_address',
        'support_email',
        'mail_from_name',
        'email_rate',
        'email_rate_marketing',
        'email_free_monthly',
        'email_free_by_tier',
        'charging_enabled',
        'charge_threshold_default_cents',
        'sms_rate',
        'mms_multiplier',
        'email_broadcast_stream',
        'alert_500_enabled',
        'alert_500_email',
        'platform_broadcast_stream',
        // platform_from_name / platform_from_email
        // removed: mail_from_address and mail_from_name above already are the
        // platform sender, and two homes for one address is how they drift.
        'platform_postal_address',
        // MARKER-OUTREACH-SENDER: a person's name and address for outreach only; blank = the sender above
        'outreach_from_address',
        'outreach_from_name',
        // MARKER-DASH-AUDIENCE: off = no shop can customize its dashboard
        'dashboard_customize_enabled',
        // MARKER-VELOCITY: what shops will see of distributor velocity
        'velocity_settings',
    ];

    // without this the json column reads back as a
    // string and every allowance lookup silently falls through to the default.
    protected $casts = [
        'email_free_by_tier' => 'array',
        'dashboard_customize_enabled' => 'boolean',
        'velocity_settings' => 'array',
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
            // forceCreate: id isn't fillable, so create() dropped it (or threw
            // outside production) instead of making the id = 1 row.
            $row = self::forceCreate(['id' => 1]);
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

    /**
     * where a shop should write when something is wrong.
     *
     * Falls back to the platform sending address rather than a literal, because
     * that is an address someone has actually configured. A support link that
     * points at a mailbox nobody reads is worse than no link.
     */
    public static function supportEmail(): string
    {
        $stored = trim((string) (self::current()->support_email ?? ''));

        return $stored !== '' ? $stored : self::fromAddress();
    }

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

    /**
     * MARKER-OUTREACH-SENDER: who outreach emails come from, [address, name].
     * Each part falls back to the platform sender when blank.
     */
    public static function outreachFrom(): array
    {
        $s = self::current();
        $addr = trim((string) ($s->outreach_from_address ?? ''));
        $name = trim((string) ($s->outreach_from_name ?? ''));

        return [
            $addr !== '' ? $addr : (self::fromAddress() ?: (string) config('mail.from.address', 'hello@intake.works')),
            $name !== '' ? $name : (self::fromName() ?: 'Intake'),
        ];
    }

    /** True when mail would still go out as the framework placeholder. */
    public static function isPlaceholder(): bool
    {
        return self::fromAddress() === null;
    }
}
