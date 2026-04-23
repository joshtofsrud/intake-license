<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantWorkOrderField extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'label',
        'field_type',
        'options',
        'help_text',
        'is_required',
        'is_identifier',
        'is_customer_visible',
        'sort_order',
    ];

    protected $casts = [
        'options'              => 'array',
        'is_required'          => 'boolean',
        'is_identifier'        => 'boolean',
        'is_customer_visible'  => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(TenantAppointmentWorkOrderResponse::class, 'field_id');
    }
}
