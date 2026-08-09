<?php

namespace App\Models\Tenant;

// MARKER-REWIND
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantPageRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null; // revisions are immutable

    protected $fillable = [
        'tenant_id', 'page_id', 'label', 'actor_name', 'section_count', 'snapshot',
    ];

    protected $casts = [
        'snapshot'   => 'array',
        'created_at' => 'datetime',
    ];

    public function page()
    {
        return $this->belongsTo(TenantPage::class, 'page_id');
    }
}
