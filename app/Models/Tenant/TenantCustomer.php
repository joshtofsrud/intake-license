<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Tenant;

class TenantCustomer extends Authenticatable
{
    use HasUuids, Notifiable;

    protected $table    = 'tenant_customers';
    protected $fillable = [
        'tenant_id','first_name','last_name','email','phone',
        'address_line1','address_line2','city','state','postcode','country',
        'notes','stripe_customer_id','wp_source_url',
        'password','remember_token','email_verified_at',
        'password_reset_token','password_reset_sent_at',
        'is_vip',
    ];

    protected $hidden = ['password','remember_token'];

    protected $casts = [
        'email_verified_at'      => 'datetime',
        'password_reset_sent_at' => 'datetime',
        'password'               => 'hashed',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function appointments(): HasMany   { return $this->hasMany(TenantAppointment::class, 'customer_id'); }
    public function specialOrders(): HasMany  { return $this->hasMany(TenantSpecialOrder::class, 'customer_id'); }
    public function notes(): HasMany          { return $this->hasMany(TenantCustomerNote::class, 'customer_id')->orderByDesc('created_at'); }
    public function fullName(): string        { return $this->first_name . ' ' . $this->last_name; }

    public function packs(): HasMany
    {
        return $this->hasMany(TenantCustomerPack::class, 'customer_id');
    }

    public function activePacks(): HasMany
    {
        return $this->packs()->where('status', 'active')
                    ->where('credits_remaining', '>', 0)
                    ->where('expires_at', '>=', now()->toDateString())
                    ->orderBy('expires_at');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantCustomerMembership::class, 'customer_id');
    }

    public function activeMembership(): ?TenantCustomerMembership
    {
        return $this->memberships()->where('status', 'active')->with('product')->first();
    }

    public function classRegistrations(): HasMany
    {
        return $this->hasMany(TenantClassRegistration::class, 'customer_id');
    }

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthPassword(): string { return $this->password ?? ''; }
}
