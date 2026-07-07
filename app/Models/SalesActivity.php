<?php
// MARKER-SALES-ACTIVITY

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesActivity extends Model
{
    use HasUuids;

    protected $table = 'sales_activities';

    protected $fillable = [
        'sales_prospect_id', 'type', 'stage_from', 'stage_to', 'body', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public const TYPES = [
        'note'         => 'Note',
        'email'        => 'Email',
        'call'         => 'Call',
        'demo'         => 'Demo',
        'follow_up'    => 'Follow-up',
        'stage_change' => 'Stage change',
        'system'       => 'System',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(SalesProspect::class, 'sales_prospect_id');
    }

    public function icon(): string
    {
        return match ($this->type) {
            'email'        => 'heroicon-o-envelope',
            'call'         => 'heroicon-o-phone',
            'demo'         => 'heroicon-o-presentation-chart-line',
            'follow_up'    => 'heroicon-o-clock',
            'stage_change' => 'heroicon-o-arrow-right-circle',
            'system'       => 'heroicon-o-bolt',
            default        => 'heroicon-o-chat-bubble-left',
        };
    }
}
