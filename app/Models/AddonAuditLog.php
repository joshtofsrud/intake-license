<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddonAuditLog extends Model
{
    protected $table = 'addon_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'addon_code',
        'action',
        'actor_type',
        'actor_id',
        'actor_label',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
