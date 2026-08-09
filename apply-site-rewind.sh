#!/usr/bin/env bash
set -euo pipefail
# apply-site-rewind.sh — MARKER-REWIND
# Undo for the page builder. Nothing in tenant_pages / tenant_page_sections was
# versioned before this, so a mis-drag or a wrong delete was permanent.
#
#   - tenant_page_revisions: a JSON snapshot of a page (meta + every section)
#   - snapshots are taken BEFORE each destructive action, labelled with what is
#     about to happen ("Deleted Hero", "Reordered 5 sections", "Applied Maple")
#   - BASELINE BACKFILL: every existing page with sections gets a snapshot the
#     moment this migrates, so live tenants have a restore point immediately
#   - History drawer in the builder topbar, replacing the two disabled
#     "coming in phase 4" undo/redo buttons
#   - Restore snapshots the current state first, so rewind is itself reversible
#   - Restore rebuilds sections and page meta but NEVER changes is_published,
#     so rewinding is always a draft-side action and can't push a live site
#     backwards on its own
#   - Coalescing: consecutive edits by the same person inside 3 minutes reuse
#     the existing restore point rather than burying it under near-identical
#     entries. The OLDER snapshot is kept, because that is the one worth
#     rewinding to after a burst of edits.

MIG_T=database/migrations/2026_08_09_120000_create_tenant_page_revisions.php
MIG_B=database/migrations/2026_08_09_120100_backfill_tenant_page_revisions.php
MODEL=app/Models/Tenant/TenantPageRevision.php
SVC=app/Services/Tenant/PageRevisionService.php
CTRL=app/Http/Controllers/Tenant/PageRevisionController.php
PB=app/Http/Controllers/Tenant/PageBuilderController.php
ST=app/Http/Controllers/Tenant/SiteTemplateController.php
ROUTES=routes/web.php
EDIT=resources/views/tenant/pages/edit.blade.php

for f in "$PB" "$ST" "$ROUTES" "$EDIT"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-REWIND" "$PB"; then
  echo "Already applied (MARKER-REWIND present) — no-op."
  exit 0
fi

# ================================================================ migrations
if [ -f "$MIG_T" ]; then echo "ok   revisions migration already present"; else
cat <<'EOF' > "$MIG_T"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-REWIND — one row per restore point. `snapshot` holds the page meta
// plus every section, so a restore needs no other table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_page_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Deliberately NOT a foreign key: a revision has to outlive the
            // page it describes, or deleting a page would delete its own undo.
            $table->uuid('page_id')->index();

            $table->string('label');                       // "Deleted Hero"
            $table->string('actor_name')->nullable();      // who triggered it
            $table->unsignedSmallInteger('section_count')->default(0);
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_page_revisions');
    }
};
EOF
echo "ok   revisions migration created"; fi

if [ -f "$MIG_B" ]; then echo "ok   backfill migration already present"; else
cat <<'EOF' > "$MIG_B"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// MARKER-REWIND — live tenants already have sites built. Give every one of
// them a restore point the moment rewind ships, rather than only protecting
// edits made from here on. Chunked so a large install doesn't load every
// section into memory at once.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tenant_pages')->orderBy('id')->chunk(100, function ($pages) use ($now) {
            $rows = [];

            foreach ($pages as $page) {
                $sections = DB::table('tenant_page_sections')
                    ->where('page_id', $page->id)
                    ->orderBy('sort_order')->get();

                if ($sections->isEmpty()) {
                    continue; // nothing worth restoring to
                }

                $rows[] = [
                    'id'            => (string) Str::uuid(),
                    'tenant_id'     => $page->tenant_id,
                    'page_id'       => $page->id,
                    'label'         => 'Starting point (saved when history was turned on)',
                    'actor_name'    => null,
                    'section_count' => $sections->count(),
                    'snapshot'      => json_encode([
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
                            'content'      => json_decode($s->content ?? '[]', true),
                            'bg_color'     => $s->bg_color,
                            'padding'      => $s->padding,
                            'is_visible'   => (bool) $s->is_visible,
                            'sort_order'   => (int) $s->sort_order,
                        ])->all(),
                    ]),
                    'created_at' => $now,
                ];
            }

            if ($rows) {
                DB::table('tenant_page_revisions')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        DB::table('tenant_page_revisions')
            ->where('label', 'Starting point (saved when history was turned on)')
            ->delete();
    }
};
EOF
echo "ok   backfill migration created"; fi

# ================================================================ model
if [ -f "$MODEL" ]; then echo "ok   model already present"; else
cat <<'EOF' > "$MODEL"
<?php

namespace App\Models\Tenant;

// MARKER-REWIND
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantPageRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null; // revisions are immutable

    protected $fillable = [
        'tenant_id', 'page_id', 'label', 'actor_name', 'section_count', 'snapshot',
    ];

    protected $casts = [
        'snapshot'   => 'array',
        'created_at' => 'datetime',
    ];

    public function page()
    {
        return $this->belongsTo(TenantPage::class, 'page_id');
    }
}
EOF
echo "ok   model created"; fi

# ================================================================ service
if [ -f "$SVC" ]; then echo "ok   service already present"; else
cat <<'EOF' > "$SVC"
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
EOF
echo "ok   service created"; fi

# ================================================================ controller
if [ -f "$CTRL" ]; then echo "ok   revision controller already present"; else
cat <<'EOF' > "$CTRL"
<?php

namespace App\Http\Controllers\Tenant;

// MARKER-REWIND — history drawer backend.

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageRevision;
use App\Services\Tenant\PageRevisionService;
use Illuminate\Http\Request;

class PageRevisionController extends Controller
{
    public function __construct(private PageRevisionService $revisions) {}

    public function index(Request $request, string $id)
    {
        $tenant = tenant();

        TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $rows = TenantPageRevision::where('tenant_id', $tenant->id)
            ->where('page_id', $id)
            ->orderByDesc('created_at')->limit(PageRevisionService::KEEP)->get();

        return response()->json([
            'revisions' => $rows->map(fn ($r) => [
                'id'       => $r->id,
                'label'    => $r->label,
                'actor'    => $r->actor_name,
                'sections' => $r->section_count,
                'when'     => $r->created_at?->diffForHumans(),
                'exact'    => $r->created_at ? tlocal_datetime($r->created_at, 'M j, Y g:i A') : null,
            ])->all(),
        ]);
    }

    public function restore(Request $request, string $id, string $revisionId)
    {
        $tenant = tenant();

        $rev = TenantPageRevision::where('tenant_id', $tenant->id)
            ->where('page_id', $id)->where('id', $revisionId)->firstOrFail();

        $page = $this->revisions->restore($rev);

        return redirect()->route('tenant.pages.edit', $page->id)
            ->with('success', 'Rewound to "' . $rev->label . '". You can undo this from History.');
    }
}
EOF
echo "ok   revision controller created"; fi

# ================================================================ hooks
python3 - "$PB" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# section ops — one choke point covers add / update / delete / reorder
edit("""        $op = $request->input('section_op');
        $pageId = $request->input('page_id');
        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $pageId)->firstOrFail();
""",
"""        $op = $request->input('section_op');
        $pageId = $request->input('page_id');
        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $pageId)->firstOrFail();

        // MARKER-REWIND — capture BEFORE the change, labelled with what is
        // about to happen. 'add' is skipped: adding a section is undone by
        // deleting it, and snapshotting every add buries the useful points.
        if ($op !== 'add') {
            $label = match ($op) {
                'delete'  => 'Deleted ' . $this->sectionLabel($page, $request->input('section_id')),
                'reorder' => 'Reordered sections',
                default   => 'Edited ' . $this->sectionLabel($page, $request->input('section_id')),
            };
            app(\\App\\Services\\Tenant\\PageRevisionService::class)
                ->snapshot($page, $label, $op === 'delete');
        }
""",
"section op snapshot")

# page meta update
edit("""        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $op = $request->input('op', 'update_page');
""",
"""        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $op = $request->input('op', 'update_page');

        if ($op === 'update_page') { // MARKER-REWIND
            app(\\App\\Services\\Tenant\\PageRevisionService::class)
                ->snapshot($page, 'Edited page settings');
        }
""",
"page update snapshot")

# page delete — forced, so the page can be brought back
edit("""        if ($page->slug === 'book') return back()->with('error', 'The Booking page cannot be deleted.'); // MARKER-PATCH-603
        $page->delete();""",
"""        if ($page->slug === 'book') return back()->with('error', 'The Booking page cannot be deleted.'); // MARKER-PATCH-603

        // MARKER-REWIND — the revision outlives the page (no FK on page_id),
        // so History can rebuild a deleted page.
        app(\\App\\Services\\Tenant\\PageRevisionService::class)
            ->snapshot($page, 'Deleted page "' . $page->title . '"', true);

        $page->delete();""",
"page delete snapshot")

# helper for readable labels
tail = src.rstrip()
if not tail.endswith('}'):
    print("FAIL helper: file does not end with }"); sys.exit(1)
helper = '''
    /** MARKER-REWIND — human label for a section, for the history list. */
    private function sectionLabel(TenantPage $page, $sectionId): string
    {
        if (! $sectionId) {
            return 'a section';
        }

        $section = TenantPageSection::where('page_id', $page->id)
            ->where('id', $sectionId)->first();

        if (! $section) {
            return 'a section';
        }

        return ucwords(str_replace('_', ' ', $section->section_type));
    }
}
'''
src = tail[:-1].rstrip('\n') + '\n' + helper
print("ok   sectionLabel helper")

open(path, 'w').write(src)
PY

python3 - "$ST" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """        // MARKER-PATCH-264 — opt-in: also rebuild the home page from the blueprint.
        if ($request->boolean('seed_layout') && $this->templates->seedLayout(tenant(), $key)) {"""
new = """        // MARKER-REWIND — rebuilding the homepage replaces every section, so
        // take a labelled restore point first. This is what makes the rebuild
        // a safe action instead of a one-way door.
        if ($request->boolean('seed_layout')) {
            $home = \\App\\Models\\Tenant\\TenantPage::where('tenant_id', tenant()->id)
                ->where('is_home', true)->first();
            if ($home) {
                app(\\App\\Services\\Tenant\\PageRevisionService::class)
                    ->snapshot($home, 'Before applying ' . $name . ' layout', true);
            }
        }

        // MARKER-PATCH-264 — opt-in: also rebuild the home page from the blueprint.
        if ($request->boolean('seed_layout') && $this->templates->seedLayout(tenant(), $key)) {"""
n = src.count(old)
if n != 1:
    print(f"FAIL template snapshot: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   template rebuild snapshot")

open(path, 'w').write(src)
PY

# ================================================================ routes
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            Route::post('/pages/{id}/sections/reorder',   [TenantControllers\\PageBuilderController::class, 'reorderSections'])->name('pages.sections.reorder');"""
new = """            Route::post('/pages/{id}/sections/reorder',   [TenantControllers\\PageBuilderController::class, 'reorderSections'])->name('pages.sections.reorder');
            // MARKER-REWIND
            Route::get('/pages/{id}/history',                        [TenantControllers\\PageRevisionController::class, 'index'])->name('pages.history');
            Route::post('/pages/{id}/history/{revisionId}/restore',  [TenantControllers\\PageRevisionController::class, 'restore'])->name('pages.history.restore');"""
n = src.count(old)
if n != 1:
    print(f"FAIL routes: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   history routes")

open(path, 'w').write(src)
PY

# ================================================================ builder UI
python3 - "$EDIT" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

# Replace the two placeholder buttons with a working History control.
old = """      <button class="pb2-icon-btn disabled" title="Undo (coming in phase 4)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
      </button>
      <button class="pb2-icon-btn disabled" title="Redo (coming in phase 4)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/></svg>
      </button>"""
new = """      {{-- MARKER-REWIND — replaces the phase-4 undo/redo placeholders --}}
      <button class="pb2-icon-btn" type="button" id="pb2-history-btn" title="History — rewind this page">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
      </button>"""
n = src.count(old)
if n != 1:
    print(f"FAIL topbar buttons: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   history button")

# Drawer + behaviour, appended before the closing @endsection.
# This view opens @section('content') and never closes it — the file ends on
# @endpush. So anchor to the one @push('scripts') and insert just above it,
# keeping the drawer inside the content section.
anchor = "@push('scripts')"
n = src.count(anchor)
if n != 1:
    print(f"FAIL drawer: @push('scripts') found {n} times"); sys.exit(1)

drawer = """
{{-- MARKER-REWIND — history drawer --}}
<style>
#pb2-hist{position:fixed;top:0;right:0;bottom:0;width:352px;z-index:1300;background:#151515;
  border-left:.5px solid rgba(255,255,255,.16);box-shadow:-26px 0 64px -22px rgba(0,0,0,.85);
  color:#f1f1f1;font-size:13px;display:flex;flex-direction:column}
#pb2-hist[hidden]{display:none}
#pb2-hist .hh{display:flex;align-items:center;justify-content:space-between;padding:13px 15px;
  border-bottom:.5px solid rgba(255,255,255,.09);background:#1d1d1d}
#pb2-hist .hh b{font-size:13px;font-weight:600}
#pb2-hist .hx{background:0;border:0;color:rgba(255,255,255,.4);font-size:19px;line-height:1;cursor:pointer}
#pb2-hist .hx:hover{color:#fff}
#pb2-hist .hnote{padding:10px 15px;font-size:11px;color:rgba(255,255,255,.55);line-height:1.5;
  border-bottom:.5px solid rgba(255,255,255,.09)}
#pb2-hist-rows{flex:1;overflow-y:auto;padding:8px}
#pb2-hist .hrow{padding:10px 11px;border-radius:9px;display:flex;gap:10px;align-items:flex-start}
#pb2-hist .hrow:hover{background:rgba(255,255,255,.05)}
#pb2-hist .hrow .hmain{flex:1;min-width:0}
#pb2-hist .hlbl{font-weight:600;font-size:12.5px}
#pb2-hist .hmeta{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px}
#pb2-hist .hbtn{background:#242424;border:.5px solid rgba(255,255,255,.16);color:#f1f1f1;
  font:inherit;font-size:11px;padding:5px 10px;border-radius:6px;cursor:pointer;flex:0 0 auto}
#pb2-hist .hbtn:hover{border-color:var(--ia-accent,#3FD16B);color:var(--ia-accent,#3FD16B)}
#pb2-hist .hempty{padding:26px 15px;text-align:center;color:rgba(255,255,255,.35);font-size:12.5px}
</style>

<div id="pb2-hist" hidden aria-label="Page history">
  <div class="hh"><b>History</b><button type="button" class="hx" id="pb2-hist-x" aria-label="Close">&times;</button></div>
  <div class="hnote">Rewinding changes your draft only &mdash; your live page stays as it is until you publish. Every rewind can itself be undone.</div>
  <div id="pb2-hist-rows"><div class="hempty">Loading&hellip;</div></div>
</div>

<form method="POST" id="pb2-hist-form" style="display:none">@csrf</form>

<script>
  (function () {
    var btn   = document.getElementById('pb2-history-btn');
    var panel = document.getElementById('pb2-hist');
    var rows  = document.getElementById('pb2-hist-rows');
    var form  = document.getElementById('pb2-hist-form');
    if (!btn || !panel) { return; }

    var listUrl = @json(route('tenant.pages.history', $page->id));
    // Built from the named route so any group prefix is honoured.\n    var restoreTpl = @json(route('tenant.pages.history.restore', [$page->id, '__RID__']));

    function close() { panel.hidden = true; }

    document.getElementById('pb2-hist-x').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { close(); }
    });

    btn.addEventListener('click', function () {
      if (!panel.hidden) { close(); return; }
      panel.hidden = false;
      rows.textContent = '';
      var loading = document.createElement('div');
      loading.className = 'hempty';
      loading.textContent = 'Loading\\u2026';
      rows.appendChild(loading);

      fetch(listUrl, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { revisions: [] }; })
        .then(function (data) { render(data.revisions || []); })
        .catch(function () { render(null); });
    });

    function render(list) {
      rows.textContent = '';
      if (list === null) {
        var err = document.createElement('div');
        err.className = 'hempty';
        err.textContent = "Couldn't load history \\u2014 try again.";
        rows.appendChild(err);
        return;
      }
      if (!list.length) {
        var none = document.createElement('div');
        none.className = 'hempty';
        none.textContent = 'No restore points yet. One is saved automatically before each change.';
        rows.appendChild(none);
        return;
      }

      list.forEach(function (r, i) {
        var row = document.createElement('div');
        row.className = 'hrow';

        var main = document.createElement('div');
        main.className = 'hmain';
        var lbl = document.createElement('div');
        lbl.className = 'hlbl';
        lbl.textContent = r.label;
        var meta = document.createElement('div');
        meta.className = 'hmeta';
        var bits = [];
        if (r.when) { bits.push(r.when); }
        if (r.actor) { bits.push(r.actor); }
        bits.push(r.sections + (r.sections === 1 ? ' section' : ' sections'));
        meta.textContent = bits.join(' \\u00b7 ');
        if (r.exact) { meta.title = r.exact; }
        main.appendChild(lbl);
        main.appendChild(meta);
        row.appendChild(main);

        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'hbtn';
        b.textContent = i === 0 ? 'Restore' : 'Rewind';
        b.addEventListener('click', function () {
          if (!confirm('Rewind this page to \\u201C' + r.label + '\\u201D?\\n\\nYour current draft is saved first, so you can undo this.')) { return; }
          form.action = restoreTpl.replace('__RID__', r.id);
          form.submit();
        });
        row.appendChild(b);

        rows.appendChild(row);
      });
    }
  })();
</script>

@push('scripts')"""

src = src.replace(anchor, drawer, 1)
print("ok   history drawer")

open(path, 'w').write(src)
PY

php -l "$PB"
php -l "$ST"
php -l "$SVC"
php -l "$CTRL"
php -l "$MODEL"

echo ""
echo "SUCCESS — apply-site-rewind applied."
echo "The backfill migration gives every existing page a restore point on deploy."
