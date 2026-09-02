<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// MARKER-CAMPAIGN-AUDIENCE
class TenantAudience extends Model
{
    use HasUuids;

    protected $table = 'tenant_audiences';

    protected $fillable = ['tenant_id', 'name', 'rules'];

    protected $casts = ['rules' => 'array'];
}
