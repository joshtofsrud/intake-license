<?php

// MARKER-OLD-SCHOOL

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on the pad.
 *
 * Not a customer record. customer_id points at someone so the note can be
 * found and surfaced; it is never written into their history.
 */
class TenantNote extends Model
{
    use HasUuids;

    protected $table = 'tenant_notes';

    protected $fillable = [
        'tenant_id', 'location_id', 'body', 'photos', 'customer_id',
        'created_by', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        // MARKER-OLD-SCHOOL-PHOTO — storage paths, not URLs. A stored URL
        // breaks the day the disk or domain changes.
        'photos'       => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'completed_by');
    }

    /** @return array<int,string> public URLs for display */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->map(fn ($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p))
            ->all();
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null;
    }

    /** Days since writing — what makes a stale pad visible. */
    public function ageInDays(): int
    {
        return (int) $this->created_at?->diffInDays(now());
    }
}
