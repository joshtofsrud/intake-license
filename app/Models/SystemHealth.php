<?php
// MARKER-PATCH-132

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHealth extends Model
{
    protected $table       = 'system_health';
    protected $primaryKey  = 'key';
    public    $incrementing = false;
    protected $keyType     = 'string';
    public    $timestamps  = false;
    protected $fillable    = ['key', 'value', 'updated_at'];
    protected $casts       = ['value' => 'array', 'updated_at' => 'datetime'];

    public static function read(string $key): ?array
    {
        $row = static::find($key);
        return $row?->value;
    }

    public static function write(string $key, array $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()],
        );
    }
}
