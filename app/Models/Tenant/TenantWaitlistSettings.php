<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

class TenantWaitlistSettings extends Model
{
    protected $table      = 'tenant_waitlist_settings';
    protected $primaryKey = 'tenant_id';
    public    $incrementing = false;
    protected $keyType      = 'string';

    protected $fillable = [
        'tenant_id',
        'enabled',
        'similar_match_rule',
        'exclude_first_time_customers',
        'include_cancellations',
        'include_new_openings',
        'include_manual_offers',
        'notify_sms',
        'notify_email',
        'max_entries_per_customer',
        'offer_copy_override',
    ];

    protected $casts = [
        'enabled'                       => 'boolean',
        'exclude_first_time_customers'  => 'boolean',
        'include_cancellations'         => 'boolean',
        'include_new_openings'          => 'boolean',
        'include_manual_offers'         => 'boolean',
        'notify_sms'                    => 'boolean',
        'notify_email'                  => 'boolean',
        'max_entries_per_customer'      => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function forTenant(Tenant $tenant): self
    {
        return self::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['enabled' => false]
        );
    }
}
