<?php
// MARKER-AGENCIES-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A rep group selling Intake. Commission terms live here (per agency, not
 * global). Attribution flows: agency -> prospect -> tenant, and the ledger
 * (chunk 2) accrues on collected revenue against these rates.
 */
class SalesAgency extends Model
{
    use HasUuids;

    protected $table = 'sales_agencies';

    protected $fillable = [
        'name', 'slug', 'status', 'commission_year1', 'commission_residual',
        'deal_registration', 'notes',
    ];

    protected $casts = [
        'commission_year1'    => 'decimal:4',
        'commission_residual' => 'decimal:4',
        'deal_registration'   => 'boolean',
    ];

    public const STATUSES = [
        'active'     => 'Active',
        'onboarding' => 'Onboarding',
        'paused'     => 'Paused',
    ];

    public function reps(): HasMany
    {
        return $this->hasMany(SalesRep::class, 'agency_id');
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'agency_id');
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(SalesCommissionEntry::class, 'agency_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (blank($a->slug)) {
                $a->slug = Str::slug($a->name);
            }
        });
    }
}
