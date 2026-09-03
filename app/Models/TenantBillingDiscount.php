<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-BILLING-DISCOUNTS — what Intake charges a shop, and why it is less
 * than list price.
 *
 * Not to be confused with App\Models\Tenant\TenantDiscount, which is a shop's
 * discount CODES for its own customers. Different table, different audience,
 * different money.
 */
class TenantBillingDiscount extends Model
{
    use HasUuids;

    protected $table = 'tenant_billing_discounts';

    protected $fillable = [
        'tenant_id', 'reason', 'scope', 'percent', 'amount_cents',
        'starts_on', 'ends_on', 'created_by',
    ];

    protected $casts = [
        'starts_on'    => 'date',
        'ends_on'      => 'date',
        'percent'      => 'integer',
        'amount_cents' => 'integer',
    ];

    public const SCOPES = [
        'platform' => 'Platform only',
        'addons'   => 'Add-ons only',
        'both'     => 'Platform & add-ons',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activeOn(\Carbon\CarbonInterface $date): bool
    {
        if ($this->starts_on && $date->lt($this->starts_on)) return false;
        if ($this->ends_on && $date->gt($this->ends_on))     return false;
        return true;
    }

    public function describeAmount(): string
    {
        if ($this->percent !== null)      return $this->percent . '% off';
        if ($this->amount_cents !== null) return '$' . number_format($this->amount_cents / 100, 2) . ' off';
        return '—';
    }

    public function describeWindow(): string
    {
        $from = $this->starts_on?->format('M Y') ?? '—';
        return $this->ends_on ? $from . ' – ' . $this->ends_on->format('M j, Y') : $from . ' onwards';
    }
}
