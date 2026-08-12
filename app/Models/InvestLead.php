<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-INVEST-SITE
class InvestLead extends Model
{
    protected $fillable = ['invest_token_id', 'name', 'email', 'note', 'ip'];

    public function investToken()
    {
        return $this->belongsTo(InvestToken::class);
    }
}
