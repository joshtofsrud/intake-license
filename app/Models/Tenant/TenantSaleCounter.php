<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

class TenantSaleCounter extends Model
{
    protected $table = 'tenant_sale_counters';

    protected $fillable = [
        'tenant_id', 'counter_date', 'last_seq',
    ];

    protected $casts = [
        'counter_date' => 'date',
        'last_seq'     => 'integer',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
