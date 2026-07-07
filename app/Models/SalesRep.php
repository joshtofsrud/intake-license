<?php
// MARKER-AGENCIES-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesRep extends Model
{
    use HasUuids;

    protected $table = 'sales_reps';

    protected $fillable = [
        'agency_id', 'name', 'role', 'email', 'phone', 'user_id', 'status',
        'invite_token', 'invited_at', // MARKER-REPPANEL-INVITE
    ];

    protected $casts = [
        'invited_at' => 'datetime',
    ];

    public const ROLES = [
        'principal' => 'Principal',
        'rep'       => 'Rep',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'sales_rep_id');
    }
}
