<?php
// MARKER-PATCH-629 — a payment method row. Source of truth for tenders and
// checkout options. Legacy settings keys are imported on bootstrap and synced
// back on every save so surfaces not yet on the table keep working.

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantPaymentMethod extends Model
{
    use HasUuids;

    protected $table = 'tenant_payment_methods';
    protected $fillable = [
        'tenant_id', 'method_key', 'name', 'kind', 'enabled', 'is_custom', 'mode',
        'handle', 'instructions', 'surfaces', 'link_qr', 'qb', 'sort',
    ];
    protected $casts = ['enabled' => 'boolean', 'is_custom' => 'boolean', 'link_qr' => 'boolean', 'surfaces' => 'array', 'qb' => 'array'];

    /** Default surface block. */
    private static function surf(bool $reg, string $regHint = '', bool $online = false, string $onlineHint = '', bool $booking = false, string $bookingHint = '', bool $rental = false, string $rentalHint = ''): array
    {
        return [
            'register' => ['on' => $reg,     'hint' => $regHint],
            'online'   => ['on' => $online,  'hint' => $onlineHint],
            'booking'  => ['on' => $booking, 'hint' => $bookingHint],
            'rental'   => ['on' => $rental,  'hint' => $rentalHint],
        ];
    }

    /** Built-in methods: key => defaults. Order = sort order. */
    public static function defaults(): array
    {
        return [
            'cash'         => ['name' => 'Cash',         'kind' => 'manual',     'enabled' => true,  'surfaces' => self::surf(true, 'Drawer')],
            'card_stripe'  => ['name' => 'Card',         'kind' => 'integrated', 'enabled' => true,  'surfaces' => self::surf(true, 'Tap, dip, or link', true, 'Instant', true, 'Instant confirm', true, 'Hold now')],
            'check'        => ['name' => 'Check',        'kind' => 'manual',     'enabled' => true,  'surfaces' => self::surf(true, 'Staff confirmed')],
            'store_credit' => ['name' => 'Store credit', 'kind' => 'manual',     'enabled' => true,  'surfaces' => self::surf(true, 'Customer balance')],
            'venmo'        => ['name' => 'Venmo',        'kind' => 'manual',     'enabled' => false, 'surfaces' => self::surf(true, 'Staff confirmed', false, 'Pay on pickup')],
            'cash_app'     => ['name' => 'Cash App',     'kind' => 'manual',     'enabled' => false, 'mode' => 'manual', 'surfaces' => self::surf(true, 'Staff confirmed', false, 'Pay on pickup')],
            'paypal'       => ['name' => 'PayPal',       'kind' => 'integrated', 'enabled' => false, 'surfaces' => self::surf(false, '', true, 'Instant')],
            'square'       => ['name' => 'Square',       'kind' => 'integrated', 'enabled' => false, 'surfaces' => self::surf(true, 'Terminal')],
        ];
    }

    /**
     * Ensure a tenant's rows exist; import legacy settings values on first
     * creation. Returns the ordered method list.
     */
    public static function bootstrapFor(Tenant $tenant)
    {
        $existing = static::where('tenant_id', $tenant->id)->pluck('method_key')->all();
        $s = $tenant->settings ?? [];
        $sort = 0;

        foreach (self::defaults() as $key => $d) {
            $sort += 10;
            if (in_array($key, $existing, true)) continue;

            // legacy import
            $enabled = $d['enabled'];
            $handle  = null;
            if ($key === 'card_stripe') $enabled = (bool) ($s['stripe_register_enabled'] ?? true);
            if ($key === 'venmo')     { $enabled = (bool) ($s['venmo_enabled'] ?? false);   $handle = $s['venmo_handle'] ?? null; }
            if ($key === 'cash_app')  { $enabled = (bool) ($s['cashapp_enabled'] ?? false); $handle = $s['cashapp_cashtag'] ?? null; }
            if ($key === 'paypal')      $enabled = (bool) ($s['paypal_enabled'] ?? false);
            if ($key === 'square')      $enabled = (bool) ($s['square_enabled'] ?? false);

            static::create([
                'tenant_id'  => $tenant->id,
                'method_key' => $key,
                'name'       => $d['name'],
                'kind'       => $d['kind'],
                'enabled'    => $enabled,
                'mode'       => $d['mode'] ?? null,
                'handle'     => $handle,
                'surfaces'   => $d['surfaces'],
                'sort'       => $sort,
            ]);
        }

        return static::where('tenant_id', $tenant->id)->orderBy('sort')->orderBy('created_at')->get();
    }

    /**
     * Keep legacy settings keys in step so the register/storefront (which read
     * them until stage 2 lands) always agree with this table.
     */
    public static function syncLegacyKeys(Tenant $tenant): void
    {
        $rows = static::where('tenant_id', $tenant->id)->get()->keyBy('method_key');
        $s = $tenant->settings ?? [];

        if ($m = $rows->get('card_stripe')) $s['stripe_register_enabled'] = $m->enabled;
        if ($m = $rows->get('venmo'))     { $s['venmo_enabled'] = $m->enabled;   $s['venmo_handle'] = $m->handle ?? ''; }
        if ($m = $rows->get('cash_app'))  { $s['cashapp_enabled'] = $m->enabled; $s['cashapp_cashtag'] = $m->handle ?? ''; }
        if ($m = $rows->get('paypal'))      $s['paypal_enabled'] = $m->enabled;
        if ($m = $rows->get('square'))      $s['square_enabled'] = $m->enabled;

        $tenant->update(['settings' => $s]);
    }

    /** Is this method live on a surface? */
    public function enabledOn(string $surface): bool
    {
        return $this->enabled && (bool) data_get($this->surfaces, "$surface.on", false);
    }

    public function hintFor(string $surface): string
    {
        return (string) data_get($this->surfaces, "$surface.hint", '');
    }
}

