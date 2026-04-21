<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

class TenantWaitlistSimilarMap extends Model
{
    use HasUuids;

    protected $table = 'tenant_waitlist_similar_map';

    protected $fillable = [
        'tenant_id',
        'service_item_id',
        'substitutable_service_item_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(TenantServiceItem::class, 'service_item_id');
    }

    public function substitutableServiceItem(): BelongsTo
    {
        return $this->belongsTo(TenantServiceItem::class, 'substitutable_service_item_id');
    }
}
