<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Tenant;

class TenantAppointment extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointments';
    protected $fillable = [
        'tenant_id','customer_id','ra_number',
        'customer_first_name','customer_last_name','customer_email','customer_phone',
        'appointment_date','receiving_method_snapshot','receiving_time_snapshot','tracking_number',
        'status','payment_status','payment_method',
        'stripe_payment_intent_id','paypal_order_id',
        'subtotal_cents','tax_cents','total_cents','paid_cents','staff_notes',
    ];
    protected $casts = [
        'appointment_date' => 'date',
        'subtotal_cents'   => 'integer',
        'tax_cents'        => 'integer',
        'total_cents'      => 'integer',
        'paid_cents'       => 'integer',
    ];

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo  { return $this->belongsTo(TenantCustomer::class, 'customer_id'); }
    public function items(): HasMany       { return $this->hasMany(TenantAppointmentItem::class, 'appointment_id'); }
    public function addons(): HasMany      { return $this->hasMany(TenantAppointmentAddon::class, 'appointment_id'); }
    public function responses(): HasMany   { return $this->hasMany(TenantAppointmentResponse::class, 'appointment_id'); }
    public function notes(): HasMany       { return $this->hasMany(TenantAppointmentNote::class, 'appointment_id')->orderBy('created_at'); }
    public function charges(): HasMany     { return $this->hasMany(TenantAppointmentCharge::class, 'appointment_id'); }

    public function scopeActive($q)        { return $q->whereNotIn('status', ['cancelled','refunded']); }
    public function customerName(): string { return $this->customer_first_name . ' ' . $this->customer_last_name; }
    public function isPaid(): bool         { return $this->payment_status === 'paid'; }
}
