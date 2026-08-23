#!/usr/bin/env python3
"""Pages: make publishing reachable.

`is_published` is only written by op=update_page, and NOTHING in the app
ever posted that op — no button, no toggle, no form. Every page a tenant
created stayed a draft forever, changeable only by editing the database.
`is_in_nav` rode the same unreachable op.

Adds:
  * A Status box at the top of the editor's inspector column — plain
    language about who can see the page, then one button. Published
    state offers View live / Unpublish plus the nav toggle.
  * Publish / Unpublish straight from the pages list, so you don't have
    to open the editor to flip one.
  * published_at, so "Published Aug 23" is a fact rather than a guess.

The controller branch already exists and already snapshots a revision
for Site Rewind, so this is a control, not new plumbing.
Run from repo root: python3 apply-page-publish-controls.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

CTRL  = 'app/Http/Controllers/Tenant/PageBuilderController.php'
EDIT  = 'resources/views/tenant/pages/edit.blade.php'
INDEX = 'resources/views/tenant/pages/index.blade.php'

# ============================================================
# 1) Migration — published_at
# ============================================================
mig = 'database/migrations/2026_08_23_120000_add_published_at_to_tenant_pages.php'
if os.path.exists(os.path.join(ROOT, mig)):
    print("SKIP (exists): migration")
else:
    write(mig, """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

// MARKER-PAGE-PUBLISH — when a page went live, so the status box can say
// so instead of implying it.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dateTime('published_at')->nullable()->after('is_published');
        });

        // Anything already flagged published predates this column; stamp it
        // with its last update so the UI doesn't read "never".
        \\Illuminate\\Support\\Facades\\DB::table('tenant_pages')
            ->where('is_published', true)
            ->whereNull('published_at')
            ->update(['published_at' => \\Illuminate\\Support\\Facades\\DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
""")
    print("OK: migration")

# ============================================================
# 2) Model
# ============================================================
sub('app/Models/Tenant/TenantPage.php',
    "'is_home','is_splash','is_published','is_in_nav','nav_order',",
    "'is_home','is_splash','is_published','published_at','is_in_nav','nav_order', // MARKER-PAGE-PUBLISH",
    "model: fillable")

sub('app/Models/Tenant/TenantPage.php',
    "'is_published' => 'boolean', 'is_in_nav' => 'boolean',",
    "'is_published' => 'boolean', 'published_at' => 'datetime', 'is_in_nav' => 'boolean',",
    "model: published_at cast")

# ============================================================
# 3) Controller — a dedicated publish op (keeps update_page intact)
# ============================================================
sub(CTRL,
    """        if ($op === 'update_page') {
            $page->update([""",
    """        // MARKER-PAGE-PUBLISH — a single-purpose op so a publish click can't
        // carry stale title/meta values from a form the user never opened.
        if ($op === 'set_published') {
            $want = (bool) $request->input('is_published', 0);

            if ($want && $page->sections()->count() === 0) {
                $msg = 'Add at least one section before publishing — an empty page is worse than no page.';
                if ($request->expectsJson()) return response()->json(['ok' => false, 'error' => $msg], 422);
                return back()->with('error', $msg);
            }

            app(\\App\\Services\\Tenant\\PageRevisionService::class)
                ->snapshot($page, $want ? 'Published' : 'Unpublished');

            $page->update([
                'is_published' => $want,
                'published_at' => $want ? ($page->published_at ?? now()) : null,
            ]);

            $msg = $want
                ? $page->title . ' is live at ' . ($page->is_home ? '/' : '/' . $page->slug) . '.'
                : $page->title . ' is back to a draft — visitors can no longer reach it.';

            if ($request->expectsJson()) return response()->json(['ok' => true, 'is_published' => $want]);
            return back()->with('success', $msg);
        }

        // MARKER-PAGE-PUBLISH — nav visibility was equally unreachable.
        if ($op === 'set_in_nav') {
            $page->update(['is_in_nav' => (bool) $request->input('is_in_nav', 0)]);
            if ($request->expectsJson()) return response()->json(['ok' => true]);
            return back()->with('success', $page->is_in_nav
                ? $page->title . ' now appears in your site navigation.'
                : $page->title . ' is hidden from your site navigation.');
        }

        if ($op === 'update_page') {
            $page->update([""",
    "controller: set_published + set_in_nav")

sub(CTRL,
    """                'is_published'     => (bool) $request->input('is_published', 0),""",
    """                'is_published'     => (bool) $request->input('is_published', 0),
                'published_at'     => (bool) $request->input('is_published', 0)
                                        ? ($page->published_at ?? now()) : null, // MARKER-PAGE-PUBLISH""",
    "controller: update_page stamps published_at")

# ============================================================
# 4) Editor — Status box above the Sections dock
# ============================================================
sub(EDIT,
    """    {{-- MARKER-PATCH-276 — sections docked above the inspector --}}
    <div class="pb2-sections-docked" id="pb2-sections-pane">""",
    """    {{-- MARKER-PAGE-PUBLISH — publishing had no control anywhere in the app.
         This box states who can see the page right now, then offers the one
         action that changes it. --}}
    @php
      $pubUrl  = $page->is_home ? '/' : '/' . $page->slug;
      $pubHost = parse_url($previewUrl ?? '', PHP_URL_HOST);
      $pubEmpty = $sections->count() === 0;
    @endphp
    <div class="pb2-status {{ $page->is_published ? 'is-live' : 'is-draft' }}">
      <div class="pb2-status-head">
        <span class="pb2-status-dot"></span>
        <span class="pb2-status-label">{{ $page->is_published ? 'Live' : 'Draft' }}</span>
        @if($page->is_published && $page->published_at)
          <span class="pb2-status-since">since {{ tlocal_date($page->published_at, 'M j') }}</span>
        @endif
      </div>

      <div class="pb2-status-say">
        @if($page->is_published)
          Anyone can visit <span class="pb2-status-url">{{ $pubHost }}{{ $pubUrl }}</span>.
        @else
          Only you can see this. Visitors to <span class="pb2-status-url">{{ $pubUrl }}</span> get a 404.
        @endif
      </div>

      <div class="pb2-status-acts">
        @if($page->is_published)
          <a class="pb2-status-btn" href="{{ rtrim($previewUrl ?? '', '/') . $pubUrl }}" target="_blank" rel="noopener">View live ↗</a>
          <form method="POST" action="{{ route('tenant.pages.update', $page->id) }}" style="flex:1">
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="set_published">
            <input type="hidden" name="is_published" value="0">
            <button type="submit" class="pb2-status-btn pb2-status-btn--off" style="width:100%">Unpublish</button>
          </form>
        @else
          <form method="POST" action="{{ route('tenant.pages.update', $page->id) }}" style="flex:1">
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="set_published">
            <input type="hidden" name="is_published" value="1">
            <button type="submit" class="pb2-status-btn pb2-status-btn--go" style="width:100%"
                    @disabled($pubEmpty)>Publish page</button>
          </form>
        @endif
      </div>

      @if($pubEmpty && !$page->is_published)
        <div class="pb2-status-hint">Add a section first — there's nothing on this page yet.</div>
      @endif

      @if(!$page->is_home)
        <form method="POST" action="{{ route('tenant.pages.update', $page->id) }}" class="pb2-status-nav">
          @csrf @method('PATCH')
          <input type="hidden" name="op" value="set_in_nav">
          <input type="hidden" name="is_in_nav" value="{{ $page->is_in_nav ? 0 : 1 }}">
          <button type="submit" class="pb2-status-navbtn">
            <span class="pb2-status-check {{ $page->is_in_nav ? 'on' : '' }}"></span>
            Show in site navigation
          </button>
        </form>
      @endif
    </div>

    {{-- MARKER-PATCH-276 — sections docked above the inspector --}}
    <div class="pb2-sections-docked" id="pb2-sections-pane">""",
    "editor: status box")

sub(EDIT,
    """.pb2-pane-footer {
  border-top: 0.5px solid var(--pb2-border);""",
    """/* MARKER-PAGE-PUBLISH */
.pb2-status {
  border-bottom: 0.5px solid var(--pb2-border);
  padding: 14px 18px;
  flex: 0 0 auto;
}
.pb2-status-head { display: flex; align-items: center; gap: 8px; }
.pb2-status-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.pb2-status.is-live  .pb2-status-dot { background: var(--pb2-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--pb2-accent) 22%, transparent); }
.pb2-status.is-draft .pb2-status-dot { background: var(--pb2-text-faint); box-shadow: 0 0 0 3px rgba(127,127,127,.14); }
.pb2-status-label { font-size: 12.5px; font-weight: 700; letter-spacing: .01em; }
.pb2-status.is-live .pb2-status-label { color: var(--pb2-accent); }
.pb2-status-since { font-size: 10.5px; color: var(--pb2-text-faint); margin-left: auto; }
.pb2-status-say { font-size: 11.5px; color: var(--pb2-text-dim); line-height: 1.55; margin-top: 7px; }
.pb2-status-url { font-family: var(--pb2-mono); font-size: 11px; color: var(--pb2-text); }
.pb2-status-acts { display: flex; gap: 7px; margin-top: 12px; }
.pb2-status-acts form { margin: 0; }
.pb2-status-btn {
  display: inline-block; text-align: center; text-decoration: none;
  border: 1px solid var(--pb2-border-strong, rgba(255,255,255,.2));
  background: none; color: var(--pb2-text);
  border-radius: 8px; padding: 7px 12px;
  font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit;
  white-space: nowrap;
}
.pb2-status-btn:hover { background: rgba(127,127,127,.08); }
.pb2-status-btn--go { background: var(--pb2-accent); border-color: var(--pb2-accent); color: #10160a; }
.pb2-status-btn--go:hover { filter: brightness(1.06); background: var(--pb2-accent); }
.pb2-status-btn--go:disabled { opacity: .4; cursor: not-allowed; }
.pb2-status-btn--off:hover { border-color: rgba(240,120,120,.5); color: #F0999B; }
.pb2-status-hint { font-size: 11px; color: var(--pb2-text-faint); margin-top: 8px; line-height: 1.5; }
.pb2-status-nav { margin: 10px 0 0; }
.pb2-status-navbtn {
  display: flex; align-items: center; gap: 8px; width: 100%;
  background: none; border: none; padding: 0; cursor: pointer;
  font-family: inherit; font-size: 11.5px; color: var(--pb2-text-dim);
}
.pb2-status-navbtn:hover { color: var(--pb2-text); }
.pb2-status-check {
  width: 14px; height: 14px; border-radius: 4px; flex: none;
  border: 1px solid var(--pb2-border-strong, rgba(255,255,255,.2));
}
.pb2-status-check.on { background: var(--pb2-accent); border-color: var(--pb2-accent); }

.pb2-pane-footer {
  border-top: 0.5px solid var(--pb2-border);""",
    "editor: status styles")

# ============================================================
# 5) Pages list — publish/unpublish inline
# ============================================================
sub(INDEX,
    """          <td style="text-align:right;white-space:nowrap">
            <a href="{{ route('tenant.pages.index', ['edit' => $page->id]) }}"
               class="ia-btn ia-btn--secondary ia-btn--sm">Edit</a>""",
    """          <td style="text-align:right;white-space:nowrap">
            {{-- MARKER-PAGE-PUBLISH — flip status without opening the editor. --}}
            <form method="POST" action="{{ route('tenant.pages.update', $page->id) }}" style="display:inline">
              @csrf @method('PATCH')
              <input type="hidden" name="op" value="set_published">
              <input type="hidden" name="is_published" value="{{ $page->is_published ? 0 : 1 }}">
              <button class="ia-btn ia-btn--sm {{ $page->is_published ? '' : 'ia-btn--primary' }}"
                      @if($page->is_published) data-confirm="Unpublish '{{ $page->title }}'? Visitors will no longer be able to reach it." @endif>
                {{ $page->is_published ? 'Unpublish' : 'Publish' }}
              </button>
            </form>
            <a href="{{ route('tenant.pages.index', ['edit' => $page->id]) }}"
               class="ia-btn ia-btn--secondary ia-btn--sm">Edit</a>""",
    "list: publish button")

print("\\nDone. Post-deploy: php artisan migrate --force && php artisan view:clear")
