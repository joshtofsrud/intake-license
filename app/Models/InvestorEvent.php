<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-RECORDS
class InvestorEvent extends Model
{
    protected $fillable = ['investor_id', 'type', 'description'];

    public function investor()
    {
        return $this->belongsTo(Investor::class);
    }

    public static function log(?int $investorId, string $type, string $description): self
    {
        return static::create([
            'investor_id' => $investorId,
            'type'        => $type,
            'description' => \Illuminate\Support\Str::limit($description, 480),
        ]);
    }
}
