<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// MARKER-BILLING-NOTICES — one notice that was actually sent, and its outcome.
class BillingNotice extends Model
{
    use HasUuids;

    protected $table = 'billing_notices';

    protected $fillable = [
        'tenant_id', 'event', 'charge_run_id', 'alerted', 'emailed', 'email_to',
        'read_at', 'resolved_by_action', 'resolved_at',
    ];

    protected $casts = [
        'alerted'     => 'boolean',
        'emailed'     => 'boolean',
        'read_at'     => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function describeOutcome(): string
    {
        return match ($this->resolved_by_action) {
            'card_added'  => 'card added',
            'charged'     => 'charge went through',
            'written_off' => 'written off',
            default       => $this->read_at ? 'read, nothing done yet' : 'not opened yet',
        };
    }
}
