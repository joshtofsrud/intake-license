<?php
// MARKER-PATCH-633 — a day's drawer reconciliation row.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantDrawerDay extends Model
{
    use HasUuids;

    protected $table = 'tenant_drawer_days';
    protected $fillable = [
        'tenant_id', 'location_id', 'day', 'opening_float_cents', 'paid_out_cents',
        'paid_out_note', 'counted_cents', 'expected_cents', 'over_short_cents',
        'snapshot', 'closed_by', 'closed_at',
    ];
    protected $casts = ['day' => 'date', 'snapshot' => 'array', 'closed_at' => 'datetime'];

    public function isClosed(): bool { return $this->closed_at !== null; }
}

