<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Tenant;

class TenantCustomer extends Model
{
    use HasUuids;
    protected $table    = 'tenant_customers';
    protected $fillable = [
        'tenant_id','first_name','last_name','email','phone',
        'address_line1','address_line2','city','state','postcode','country',
        'notes','stripe_customer_id','wp_source_url',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function appointments(): HasMany   { return $this->hasMany(TenantAppointment::class, 'customer_id'); }
    public function notes(): HasMany          { return $this->hasMany(TenantCustomerNote::class, 'customer_id')->orderByDesc('created_at'); }
    public function fullName(): string        { return $this->first_name . ' ' . $this->last_name; }
}
