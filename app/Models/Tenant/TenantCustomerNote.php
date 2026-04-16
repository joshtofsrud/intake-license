<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCustomerNote extends Model
{
    use HasUuids;
    protected $table      = 'tenant_customer_notes';
    public    $timestamps = false;
    protected $fillable   = ['tenant_id','customer_id','user_id','note','created_at'];
    protected $casts      = ['created_at' => 'datetime'];
}
