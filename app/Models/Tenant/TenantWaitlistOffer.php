<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

class TenantWaitlistOffer extends Model
{
    use HasUuids;

    protected $table = 'tenant_waitlist_offers';

    protected $fillable = [
        'tenant_id',
        'waitlist_entry_id',
        'offer_token',
        'slot_datetime',
        'slot_source',
        'triggering_appointment_id',
        'notified_at',
        'viewed_at',
        'accepted_at',
        'resulting_appointment_id',
        'status',
        'offer_expires_at',
        'sms_sent',
        'email_sent',
        'sms_error',
        'email_error',
    ];

    protected $casts = [
        'slot_datetime'     => 'datetime',
        'notified_at'       => 'datetime',
        'viewed_at'         => 'datetime',
        'accepted_at'       => 'datetime',
        'offer_expires_at'  => 'datetime',
        'sms_sent'          => 'boolean',
        'email_sent'        => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TenantWaitlistEntry::class, 'waitlist_entry_id');
    }

    public function triggeringAppointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'triggering_appointment_id');
    }

    public function resultingAppointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'resulting_appointment_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'viewed'], true)
            && $this->offer_expires_at->isFuture();
    }

    public static function generateToken(): string
    {
        // 48 chars, URL-safe, unguessable
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < 48; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
