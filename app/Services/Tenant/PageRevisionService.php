<?php

namespace App\Services\Tenant;

// MARKER-REWIND — capture, restore, prune.

use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageRevision;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Support\Facades\DB;

class PageRevisionService
{
    /** Restore points kept per page. */
    public const KEEP = 30;

    /** Minutes within which one person's consecutive edits share a restore point. */
    public const COALESCE_MINUTES = 3;

    /**
     * Snapshot a page BEFORE it is changed.
     *
     * $force skips coalescing — used for the rare, genuinely destructive
     * actions (page delete, template rebuild) that always deserve their own
     * labelled entry.
     */
    public function snapshot(TenantPage $page, string $label, bool $force = false): ?TenantPageRevision
    {
        $actor = auth('tenant')->user()?->name;

        if (! $force && $this->recentlySnapshotted($page, $actor)) {
            // Keep the OLDER restore point: after a burst of edits, the state
            // before the burst is the one worth rewinding to.
            return null;
        }

        $sections = TenantPageSection::where('page_id', $page->id)
            ->orderBy('sort_order')->get();

        $rev = TenantPageRevision::create([
            'tenant_id'     => $page->tenant_id,
            'page_id'       => $page->id,
            'label'         => $label,
            'actor_name'    => $actor,
            'section_count' => $sections->count(),
            'snapshot'      => [
                'page' => [
                    'title'            => $page->title,
                    'slug'             => $page->slug,
                    'meta_title'       => $page->meta_title,
                    'meta_description' => $page->meta_description,
                    'is_home'          => (bool) $page->is_home,
                    'is_in_nav'        => (bool) $page->is_in_nav,
                    'nav_order'        => (int) $page->nav_order,
                ],
                'sections' => $sections->map(fn ($s) => [
                    'section_type' => $s->section_type,
                    'content'      => $s->content ?? [],
                    'bg_color'     => $s->bg_color,
                    'padding'      => $s->padding,
                    'is_visible'   => (bool) $s->is_visible,
                    'sort_order'   => (int) $s->sort_order,
                ])->all(),
            ],
        ]);

        $this->prune($page);

        return $rev;
    }

    private function recentlySnapshotted(TenantPage $page, ?string $actor): bool
    {
        $last = TenantPageRevision::where('page_id', $page->id)
            ->orderByDesc('created_at')->first();

        return $last
            && $last->actor_name === $actor
            && $last->created_at
            && $last->created_at->gt(now()->subMinutes(self::COALESCE_MINUTES));
    }

    /**
     * Roll a page back to a revision.
     *
     * is_published is deliberately never touched: rewinding edits the draft,
     * so it can't take a live site backwards by itself.
     */
    public function restore(TenantPageRevision $rev): TenantPage
    {
        $snap = $rev->snapshot;
        $meta = $snap['page'] ?? [];

        return DB::transaction(function () use ($rev, $snap, $meta) {
            $page = TenantPage::where('tenant_id', $rev->tenant_id)
                ->where('id', $rev->page_id)->first();

            if ($page) {
                // Snapshot where we are now, so a restore can be undone.
                $this->snapshot($page, 'Before restoring "' . $rev->label . '"', true);
            } else {
                // The page itself was deleted — bring it back, unpublished.
                $page = new TenantPage();
                $page->id           = $rev->page_id;
                $page->tenant_id    = $rev->tenant_id;
                $page->is_published = false;
            }

            $page->title            = $meta['title'] ?? $page->title ?? 'Page';
            $page->slug             = $meta['slug'] ?? $page->slug ?? 'page';
            $page->meta_title       = $meta['meta_title'] ?? null;
            $page->meta_description = $meta['meta_description'] ?? null;
            $page->is_home          = (bool) ($meta['is_home'] ?? $page->is_home ?? false);
            $page->is_in_nav        = (bool) ($meta['is_in_nav'] ?? true);
            $page->nav_order        = (int) ($meta['nav_order'] ?? 0);
            $page->save();

            TenantPageSection::where('page_id', $page->id)->delete();

            foreach (($snap['sections'] ?? []) as $i => $s) {
                TenantPageSection::create([
                    'page_id'      => $page->id,
                    'tenant_id'    => $page->tenant_id,
                    'section_type' => $s['section_type'],
                    'content'      => $s['content'] ?? [],
                    'bg_color'     => $s['bg_color'] ?? null,
                    'padding'      => $s['padding'] ?? 'normal',
                    'is_visible'   => (bool) ($s['is_visible'] ?? true),
                    'sort_order'   => (int) ($s['sort_order'] ?? $i),
                ]);
            }

            return $page;
        });
    }

    private function prune(TenantPage $page): void
    {
        $ids = TenantPageRevision::where('page_id', $page->id)
            ->orderByDesc('created_at')
            ->skip(self::KEEP)->take(100)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            TenantPageRevision::whereIn('id', $ids)->delete();
        }
    }
}
