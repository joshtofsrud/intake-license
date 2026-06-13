<?php
// MARKER-PATCH-257

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantMedia extends Model
{
    use HasUuids;

    protected $table = 'tenant_media';

    protected $fillable = [
        'tenant_id', 'filename', 'original_name', 'path', 'url', 'folder',
        'mime_type', 'bytes', 'width', 'height', 'uploaded_by', 'archived_at',
    ];

    protected $casts = [
        'bytes'       => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
        'archived_at' => 'datetime',
    ];

    /** Active (non-archived) media. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeFolder(Builder $q, ?string $folder): Builder
    {
        return $folder ? $q->where('folder', $folder) : $q;
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
