<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Tenant;

class TenantWaitlistEntry extends Model
{
    use HasUuids;

    protected $table = 'tenant_waitlist_entries';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'service_item_id',
        'addon_ids',
        'date_range_start',
        'date_range_end',
        'preferred_days',
        'preferred_time_start',
        'preferred_time_end',
        'notes',
        'status',
    ];

    protected $casts = [
        'addon_ids'            => 'array',
        'preferred_days'       => 'array',
        'date_range_start'     => 'date',
        'date_range_end'       => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(TenantServiceItem::class, 'service_item_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(TenantWaitlistOffer::class, 'waitlist_entry_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function coversDate(\DateTimeInterface $dt): bool
    {
        $date = \Carbon\Carbon::parse($dt)->startOfDay();
        return $date->betweenIncluded($this->date_range_start, $this->date_range_end);
    }

    public function matchesPreferredTime(\DateTimeInterface $dt): bool
    {
        if (!$this->preferred_time_start && !$this->preferred_time_end) return true;
        $clock = \Carbon\Carbon::parse($dt)->format('H:i:s');
        if ($this->preferred_time_start && $clock < $this->preferred_time_start) return false;
        if ($this->preferred_time_end && $clock > $this->preferred_time_end) return false;
        return true;
    }

    public function matchesPreferredDays(\DateTimeInterface $dt): bool
    {
        if (empty($this->preferred_days)) return true;
        $dow = (int) \Carbon\Carbon::parse($dt)->dayOfWeek; // 0 = Sunday
        return in_array($dow, array_map('intval', $this->preferred_days), true);
    }
}
