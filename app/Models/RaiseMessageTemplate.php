<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-SETUP
class RaiseMessageTemplate extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'subject', 'body'];

    /** DB overrides win; anything not customised falls back to the shipped config. */
    public static function merged(): array
    {
        $templates = config('investor_messages', []);

        foreach (static::all() as $row) {
            $templates[$row->key] = array_merge(
                $templates[$row->key] ?? ['label' => $row->key, 'auto' => false],
                ['subject' => $row->subject, 'body' => $row->body]
            );
        }

        return $templates;
    }
}
