<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThemeSettingsAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'theme', 'token_key', 'old_value', 'new_value', 'action', 'user_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
