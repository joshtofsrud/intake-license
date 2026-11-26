<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** MARKER-POSTS: a dated post in one of the shop's feeds. */
class TenantPost extends Model
{
    use HasUuids;

    protected $table = 'tenant_posts';

    protected $fillable = ['tenant_id', 'feed', 'title', 'slug', 'body', 'image_url', 'published_at', 'created_by'];

    protected $casts = ['published_at' => 'datetime'];

    public function scopeLive($q)
    {
        return $q->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isLive(): bool
    {
        return $this->published_at && $this->published_at->lessThanOrEqualTo(now());
    }

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }

    /** Plain-text paragraphs; blank lines split them. */
    public function paragraphs(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $this->body)), fn ($p) => $p !== ''));
    }

    public function excerpt(int $len = 180): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', trim((string) $this->body)), $len);
    }

    public static function uniqueSlug(string $tenantId, string $title, ?string $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $base = Str::limit($base, 200, '');
        $slug = $base; $n = 2;
        while (static::where('tenant_id', $tenantId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /** Feeds this shop has used, most-used first. */
    public static function feedsFor(string $tenantId): array
    {
        return static::where('tenant_id', $tenantId)
            ->selectRaw('feed, COUNT(*) as n')->groupBy('feed')->orderByDesc('n')
            ->pluck('feed')->all();
    }
}
