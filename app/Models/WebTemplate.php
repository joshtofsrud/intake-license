<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * MARKER-WEB-TEMPLATES: a site template, edited in master admin › Site templates.
 * Shops only ever read the published columns (live()); unpublished edits sit in "draft".
 */
class WebTemplate extends Model
{
    protected $table = 'web_templates';

    protected $fillable = ['key', 'name', 'description', 'status', 'plans', 'modes', 'pieces', 'sections', 'draft', 'sort', 'published_at'];

    protected $casts = [
        'plans' => 'array', 'modes' => 'array', 'pieces' => 'array', 'sections' => 'array', 'draft' => 'array',
        'published_at' => 'datetime',
    ];

    public const FIELDS = ['name', 'description', 'plans', 'modes', 'pieces', 'sections'];

    private static array $memo = [];

    /** A published template by key, cached for a minute (every public page reads it). */
    public static function cached(string $key): ?self
    {
        if (array_key_exists($key, self::$memo)) return self::$memo[$key];
        $row = Cache::remember('web-template:' . $key, 60, fn () => static::where('key', $key)->whereNotNull('published_at')->first());
        return self::$memo[$key] = $row;
    }

    public static function flush(?string $key = null): void
    {
        self::$memo = [];
        if ($key) Cache::forget('web-template:' . $key);
        \App\Support\SiteLook::forget();
    }

    /** What shops see. */
    public function live(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'description' => $this->description, 'plans' => (array) $this->plans,
            'modes' => (array) $this->modes, 'pieces' => (array) $this->pieces, 'sections' => (array) $this->sections];
    }

    /** What master admin is editing: the published fields with any unpublished edits on top. */
    public function working(): array
    {
        return array_merge($this->live(), array_intersect_key((array) $this->draft, array_flip(self::FIELDS)));
    }

    public function hasDraft(): bool
    {
        return ! empty($this->draft) && $this->published_at !== null;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function availableTo(string $tier): bool
    {
        return (bool) (((array) $this->plans)[$tier] ?? false);
    }

    /** Published templates in order, for the shop's Templates page. */
    public static function published()
    {
        return static::whereNotNull('published_at')->orderBy('sort')->orderBy('id')->get();
    }

    public function shops()
    {
        return \App\Models\Tenant::where('site_template', $this->key);
    }
}
