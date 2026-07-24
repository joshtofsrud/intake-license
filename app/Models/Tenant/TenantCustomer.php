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
        'sms_opt_out_at','sms_consent_source', // MARKER-PATCH-221
        'address_line1','address_line2','city','state','postcode','country',
        'notes','stripe_customer_id','wp_source_url',
        'password','remember_token','email_verified_at',
        'password_reset_token','password_reset_sent_at',
        'is_vip',
        // MARKER-BIZ-CUSTOMER
        'customer_type', 'business_name',
        'tax_exempt', 'tax_exempt_certificate',
        'payment_terms', 'po_required',
    ];

    protected $hidden = ['password','remember_token'];

    protected $casts = [
        'tax_exempt'             => 'boolean', // MARKER-BIZ-CUSTOMER
        'po_required'            => 'boolean', // MARKER-BIZ-CUSTOMER
        'email_verified_at'      => 'datetime',
        'password_reset_sent_at' => 'datetime',
        'password'               => 'hashed',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function appointments(): HasMany   { return $this->hasMany(TenantAppointment::class, 'customer_id'); }
    public function specialOrders(): HasMany  { return $this->hasMany(TenantSpecialOrder::class, 'customer_id'); }
    public function notes(): HasMany          { return $this->hasMany(TenantCustomerNote::class, 'customer_id')->orderByDesc('created_at'); }
    // MARKER-BIZ-CUSTOMER — one display name for the whole app. A business
    // shows its business name; an individual is unchanged. Everything that
    // renders a customer name routes through here so a business record can
    // never surface a person's name by accident.
    public function fullName(): string
    {
        if ($this->isBusiness()) {
            $name = trim((string) $this->business_name);
            if ($name !== '') {
                return $name;
            }
        }

        return trim($this->first_name . ' ' . $this->last_name);
    }

    /** The person, even for a business — used where a human is meant. */
    public function personName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function isBusiness(): bool
    {
        return $this->customer_type === self::TYPE_BUSINESS;
    }

    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_BUSINESS   = 'business';

    public const PAYMENT_TERMS = ['due_now', 'net_15', 'net_30', 'net_60'];

    public function termsLabel(): string
    {
        return match ($this->payment_terms) {
            'net_15' => 'Net 15',
            'net_30' => 'Net 30',
            'net_60' => 'Net 60',
            default  => 'Due at service',
        };
    }

    /** MARKER-BIZ-CUSTOMER — people at a business customer. */
    public function contacts()
    {
        return $this->hasMany(TenantCustomerContact::class, 'customer_id')
            ->orderByDesc('is_primary')
            ->orderBy('name');
    }

    public function primaryContact()
    {
        return $this->hasOne(TenantCustomerContact::class, 'customer_id')
            ->where('is_primary', true);
    }

    // MARKER-PATCH-158-A
    public function assets(): HasMany         { return $this->hasMany(TenantCustomerAsset::class, 'customer_id'); }
    public function activeAssets(): HasMany   { return $this->hasMany(TenantCustomerAsset::class, 'customer_id')->whereNull('archived_at'); }

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
