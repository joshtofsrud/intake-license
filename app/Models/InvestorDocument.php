<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestorDocument extends Model
{
    protected $fillable = [
        'investor_id', 'label', 'path', 'original_name', 'mime', 'size',
        'visible_to_investor', 'signed_at',
    ];

    protected $casts = [
        'visible_to_investor' => 'boolean',
        'signed_at'           => 'datetime',
    ];

    public function investor()
    {
        return $this->belongsTo(Investor::class);
    }
}
