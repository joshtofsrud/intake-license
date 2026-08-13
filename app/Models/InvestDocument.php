<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-SETUP
class InvestDocument extends Model
{
    protected $fillable = ['slug', 'label', 'path', 'sort', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public function exists(): bool
    {
        return is_file(storage_path('app/' . $this->path));
    }
}
