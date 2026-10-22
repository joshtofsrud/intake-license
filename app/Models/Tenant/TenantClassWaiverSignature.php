<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A customer agreeing to one waiver version. MARKER-CLASSES-P9 */
class TenantClassWaiverSignature extends Model
{
    use HasUuids;

    protected $table    = 'tenant_class_waiver_signatures';
    protected $fillable = ['tenant_id', 'customer_id', 'waiver_id', 'version', 'signer_name', 'method', 'ip', 'signed_by_user_id', 'signed_at'];
    protected $casts    = ['version' => 'integer', 'signed_at' => 'datetime'];
}
