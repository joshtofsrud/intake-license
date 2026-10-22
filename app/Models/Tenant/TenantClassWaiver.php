<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One version of a studio's class waiver. Never edited: a change is a new version. MARKER-CLASSES-P9 */
class TenantClassWaiver extends Model
{
    use HasUuids;

    protected $table    = 'tenant_class_waivers';
    protected $fillable = ['tenant_id', 'version', 'body', 'created_by_user_id'];
    protected $casts    = ['version' => 'integer'];
}
