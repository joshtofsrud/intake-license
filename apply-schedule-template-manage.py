#!/usr/bin/env python3
"""Schedule templates: delete, know what you're deleting, and stop the
silent overwrite.

Three defects:
  * No delete — no route, no UI. Templates accumulate forever.
  * saveTemplate used updateOrCreate keyed on name, so saving under an
    existing name REPLACED that template with no warning and no
    recovery. Quiet data loss.
  * Rows read "Apply <name>" and nothing else, so two similar names are
    indistinguishable — you can't tell which one to drop.

Now: each row shows shift count, people, and when it was last applied;
delete confirms inline (naming what's inside, and saying calendar shifts
aren't touched); saving over an existing name requires an explicit
confirm_overwrite, otherwise it comes back asking.
Run from repo root: python3 apply-schedule-template-manage.py
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

CTRL  = 'app/Http/Controllers/Tenant/SchedulingController.php'
VIEW  = 'resources/views/tenant/scheduling/index.blade.php'
MODEL = 'app/Models/Tenant/TenantShiftTemplate.php'

# ============================================================
# 1) Migration — last_applied_at, so a row can say which template is stale
# ============================================================
mig = 'database/migrations/2026_08_23_100000_add_last_applied_at_to_shift_templates.php'
if os.path.exists(os.path.join(ROOT, mig)):
    print("SKIP (exists): migration")
else:
    write(mig, """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

// MARKER-TPL-MANAGE — "last applied" is the fact that tells you which of
// two similarly-named templates is the dead one.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_shift_templates', function (Blueprint $table) {
            $table->dateTime('last_applied_at')->nullable()->after('created_by');
        });
    }
    public function down(): void
    {
        Schema::table('tenant_shift_templates', function (Blueprint $table) {
            $table->dropColumn('last_applied_at');
        });
    }
};
""")
    print("OK: migration")

# ============================================================
# 2) Model — fillable + cast
# ============================================================
sub(MODEL,
    "protected $fillable = ['tenant_id', 'name', 'pattern', 'created_by'];",
    "protected $fillable = ['tenant_id', 'name', 'pattern', 'created_by', 'last_applied_at']; // MARKER-TPL-MANAGE",
    "model: fillable")
sub(MODEL,
    "protected $casts = ['pattern' => 'array'];",
    "protected $casts = ['pattern' => 'array', 'last_applied_at' => 'datetime'];",
    "model: cast")

# ============================================================
# 3) Controller — load the pattern so the menu can describe each template
# ============================================================
sub(CTRL,
    """        $templates = \\App\\Models\\Tenant\\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->orderBy('name')->get(['id', 'name']);""",
    """        // MARKER-TPL-MANAGE — the pattern comes along so each row can say
        // what it holds; you can't choose which of two to delete otherwise.
        $templates = \\App\\Models\\Tenant\\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->orderBy('name')->get(['id', 'name', 'pattern', 'last_applied_at'])
            ->map(function ($t) {
                $pattern = (array) $t->pattern;
                $t->shift_count  = count($pattern);
                $t->people_count = count(array_unique(array_filter(array_column($pattern, 'user_id'))));
                return $t;
            });""",
    "controller: template stats")

# ============================================================
# 4) Controller — overwrite guard on save
# ============================================================
sub(CTRL,
    """        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        [$weekStart, , $fromUtc, $toUtc] = $this->week($request);""",
    """        $data = $request->validate([
            'name'              => ['required', 'string', 'max:80'],
            'confirm_overwrite' => ['nullable', 'boolean'],
        ]);
        [$weekStart, , $fromUtc, $toUtc] = $this->week($request);

        // MARKER-TPL-MANAGE — updateOrCreate on name used to replace an
        // existing template silently. Make the trade explicit instead.
        $existing = \\App\\Models\\Tenant\\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->where('name', trim($data['name']))->first();
        if ($existing && ! $request->boolean('confirm_overwrite')) {
            return back()->with('tpl_overwrite', [
                'name'  => $existing->name,
                'has'   => count((array) $existing->pattern),
                'week'  => $weekStart->toDateString(),
            ]);
        }""",
    "controller: overwrite guard")

# ============================================================
# 5) Controller — delete + stamp last_applied_at
# ============================================================
sub(CTRL,
    """    /** MARKER-PATCH-624 — apply a template onto the current week (skips conflicts). */""",
    """    /** MARKER-TPL-MANAGE — remove a saved pattern. Shifts already on the
     *  calendar are untouched; only the template row goes. */
    public function deleteTemplate(Request $request, string $templateId)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('scheduling.build'), 403);

        $tpl = \\App\\Models\\Tenant\\TenantShiftTemplate::where('tenant_id', $tenant->id)
            ->where('id', $templateId)->firstOrFail();
        $name = $tpl->name;
        $tpl->delete();

        return back()->with('success', 'Template "' . $name . '" deleted. Shifts on the calendar were not changed.');
    }

    /** MARKER-PATCH-624 — apply a template onto the current week (skips conflicts). */""",
    "controller: delete")

sub(CTRL,
    """        return back()->with('success', 'Applied "' . $tpl->name . '" — ' . $added . ' shift(s) added (conflicts skipped).');""",
    """        $tpl->update(['last_applied_at' => now()]); // MARKER-TPL-MANAGE

        return back()->with('success', 'Applied "' . $tpl->name . '" — ' . $added . ' shift(s) added (conflicts skipped).');""",
    "controller: stamp last applied")

# ============================================================
# 6) Route
# ============================================================
sub('routes/web.php',
    """            Route::post('/scheduling/template/{templateId}/apply', [TenantControllers\\SchedulingController::class, 'applyTemplate'])->name('scheduling.template.apply');""",
    """            Route::post('/scheduling/template/{templateId}/apply', [TenantControllers\\SchedulingController::class, 'applyTemplate'])->name('scheduling.template.apply');
            Route::delete('/scheduling/template/{templateId}', [TenantControllers\\SchedulingController::class, 'deleteTemplate'])->name('scheduling.template.delete'); // MARKER-TPL-MANAGE""",
    "route: delete")

# ============================================================
# 7) View — richer rows, hover delete with inline confirm, overwrite prompt
# ============================================================
sub(VIEW,
    """          @forelse($templates as $tpl)
            <form method="POST" action="{{ route('tenant.scheduling.template.apply', ['templateId' => $tpl->id, 'week' => $weekStart->toDateString()]) }}">@csrf
              <button type="submit" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--ia-text);font-size:12.5px;padding:7px 9px;border-radius:6px;cursor:pointer">Apply "{{ $tpl->name }}"</button>
            </form>
          @empty
            <span style="display:block;font-size:11.5px;color:var(--ia-text-muted);padding:7px 9px">No templates yet</span>
          @endforelse
          <form method="POST" action="{{ route('tenant.scheduling.template.save', ['week' => $weekStart->toDateString()]) }}" style="border-top:.5px solid var(--ia-border);margin-top:4px;padding:7px 9px;display:flex;gap:6px">
            @csrf
            <input type="text" name="name" required maxlength="80" placeholder="save week as…" style="flex:1;background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);color:var(--ia-text);border-radius:6px;padding:5px 8px;font-size:11.5px">
            <button class="sc-btn" type="submit" style="padding:4px 10px;font-size:11px">Save</button>
          </form>""",
    """          {{-- MARKER-TPL-MANAGE — each row says what it holds, so two similar
               names can be told apart before one gets deleted. --}}
          <div style="font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--ia-text-muted);padding:6px 9px 3px">Apply to this week</div>
          @forelse($templates as $tpl)
            <div class="sc-tpl" data-tpl-row="{{ $tpl->id }}">
              <form method="POST" action="{{ route('tenant.scheduling.template.apply', ['templateId' => $tpl->id, 'week' => $weekStart->toDateString()]) }}" style="flex:1;min-width:0">@csrf
                <button type="submit" class="sc-tpl-main">
                  <span class="sc-tpl-name">{{ $tpl->name }}</span>
                  <span class="sc-tpl-meta">
                    {{ $tpl->shift_count }} {{ Str::plural('shift', $tpl->shift_count) }} ·
                    {{ $tpl->people_count }} {{ Str::plural('person', $tpl->people_count) }} ·
                    {{ $tpl->last_applied_at ? 'last applied ' . tlocal_date($tpl->last_applied_at, 'M j') : 'never applied' }}
                  </span>
                </button>
              </form>
              <button type="button" class="sc-tpl-del" title="Delete template"
                      onclick="document.querySelector('[data-tpl-row=&quot;{{ $tpl->id }}&quot;]').style.display='none';document.querySelector('[data-tpl-confirm=&quot;{{ $tpl->id }}&quot;]').style.display='block'">&times;</button>
            </div>
            <div class="sc-tpl-confirm" data-tpl-confirm="{{ $tpl->id }}" style="display:none">
              <div class="t">Delete &ldquo;{{ $tpl->name }}&rdquo;?</div>
              <div class="s">{{ $tpl->shift_count }} {{ Str::plural('shift', $tpl->shift_count) }} across {{ $tpl->people_count }} {{ Str::plural('person', $tpl->people_count) }}. Shifts already on the calendar aren't touched — only the saved pattern goes.</div>
              <div class="r">
                <form method="POST" action="{{ route('tenant.scheduling.template.delete', ['templateId' => $tpl->id, 'week' => $weekStart->toDateString()]) }}" style="margin:0">
                  @csrf @method('DELETE')
                  <button type="submit" class="sc-xs sc-xs--danger">Delete template</button>
                </form>
                <button type="button" class="sc-xs"
                        onclick="document.querySelector('[data-tpl-confirm=&quot;{{ $tpl->id }}&quot;]').style.display='none';document.querySelector('[data-tpl-row=&quot;{{ $tpl->id }}&quot;]').style.display='flex'">Keep it</button>
              </div>
            </div>
          @empty
            <span style="display:block;font-size:11.5px;color:var(--ia-text-muted);padding:7px 9px">No templates yet</span>
          @endforelse

          {{-- MARKER-TPL-MANAGE — the name already exists: show the trade. --}}
          @if(session('tpl_overwrite'))
            @php $ow = session('tpl_overwrite'); @endphp
            <div class="sc-tpl-warn">
              <div class="t">&ldquo;{{ $ow['name'] }}&rdquo; already exists</div>
              <div class="s">Replacing it swaps its {{ $ow['has'] }} {{ Str::plural('shift', $ow['has']) }} for this week's. The old pattern can't be recovered.</div>
              <div class="r">
                <form method="POST" action="{{ route('tenant.scheduling.template.save', ['week' => $ow['week']]) }}" style="margin:0">
                  @csrf
                  <input type="hidden" name="name" value="{{ $ow['name'] }}">
                  <input type="hidden" name="confirm_overwrite" value="1">
                  <button type="submit" class="sc-xs" style="border-color:#FBBF24;color:#FBBF24">Replace it</button>
                </form>
                <button type="button" class="sc-xs" onclick="var f=document.getElementById('sc-tpl-name');f.focus();f.select()">Save as a new name</button>
              </div>
            </div>
          @endif

          <form method="POST" action="{{ route('tenant.scheduling.template.save', ['week' => $weekStart->toDateString()]) }}" style="border-top:.5px solid var(--ia-border);margin-top:4px;padding:7px 9px;display:flex;gap:6px">
            @csrf
            <input type="text" id="sc-tpl-name" name="name" required maxlength="80" value="{{ session('tpl_overwrite')['name'] ?? '' }}" placeholder="save this week as…" style="flex:1;background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);color:var(--ia-text);border-radius:6px;padding:5px 8px;font-size:11.5px">
            <button class="sc-btn" type="submit" style="padding:4px 10px;font-size:11px">Save</button>
          </form>""",
    "view: template rows + guards")

# widen the menu and add the row styles
sub(VIEW,
    """        <span id="sc-tpl-menu" style="display:none;position:absolute;right:0;top:36px;z-index:20;background:var(--ia-bg,#0c0c0c);border:1px solid var(--ia-border-2,rgba(255,255,255,.2));border-radius:9px;min-width:220px;padding:6px">""",
    """        <span id="sc-tpl-menu" style="display:none;position:absolute;right:0;top:36px;z-index:20;background:var(--ia-bg,#0c0c0c);border:1px solid var(--ia-border-2,rgba(255,255,255,.2));border-radius:9px;min-width:340px;padding:5px">""",
    "view: wider menu")

sub(VIEW,
    """.sc-mb .two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }""",
    """.sc-mb .two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
/* MARKER-TPL-MANAGE */
.sc-tpl { display:flex; align-items:center; gap:8px; border-radius:7px; padding:7px 8px; }
.sc-tpl:hover { background:var(--ia-surface-2,#1a1a1a); }
.sc-tpl-main { display:block; width:100%; text-align:left; background:none; border:none; padding:0; cursor:pointer; color:var(--ia-text); font-family:inherit; }
.sc-tpl-name { display:block; font-size:12.5px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sc-tpl-meta { display:block; font-size:10.5px; color:var(--ia-text-muted); margin-top:1px; }
.sc-tpl-del { flex:0 0 auto; background:none; border:none; color:var(--ia-text-muted); font-size:14px; line-height:1; cursor:pointer; padding:4px 6px; border-radius:5px; opacity:0; transition:opacity .13s,color .13s,background .13s; font-family:inherit; }
.sc-tpl:hover .sc-tpl-del, .sc-tpl-del:focus-visible { opacity:1; }
.sc-tpl-del:hover { color:#F0999B; background:rgba(240,120,120,.12); }
.sc-tpl-confirm { border:1px solid rgba(240,120,120,.4); background:rgba(240,120,120,.07); border-radius:7px; padding:9px 10px; margin:4px 0; }
.sc-tpl-warn { border:1px solid rgba(245,158,11,.4); background:rgba(245,158,11,.08); border-radius:7px; padding:9px 10px; margin:4px 0; }
.sc-tpl-confirm .t, .sc-tpl-warn .t { font-size:12px; font-weight:600; line-height:1.45; }
.sc-tpl-warn .t { color:#FBBF24; }
.sc-tpl-confirm .s, .sc-tpl-warn .s { font-size:11px; color:var(--ia-text-muted); margin-top:3px; line-height:1.5; }
.sc-tpl-confirm .r, .sc-tpl-warn .r { display:flex; gap:6px; margin-top:9px; }
.sc-xs { background:none; border:1px solid var(--ia-border-2,rgba(255,255,255,.2)); color:var(--ia-text); border-radius:6px; padding:4px 10px; font-size:11px; font-weight:600; cursor:pointer; font-family:inherit; }
.sc-xs--danger { background:#E88B8B; border-color:#E88B8B; color:#160b0b; }""",
    "view: styles")

# The menu must be open for the overwrite prompt to be seen.
sub(VIEW,
    """        <button class="sc-btn" type="button" onclick="document.getElementById('sc-tpl-menu').classList.toggle('on')">Templates ▾</button>""",
    """        <button class="sc-btn" type="button" onclick="document.getElementById('sc-tpl-menu').classList.toggle('on')">Templates ▾</button>
        {{-- MARKER-TPL-MANAGE — reopen the menu after a rejected save so the
             overwrite prompt isn't hidden behind a closed dropdown. --}}
        @if(session('tpl_overwrite'))
          <script>document.addEventListener('DOMContentLoaded',function(){var m=document.getElementById('sc-tpl-menu');if(m)m.classList.add('on');});</script>
        @endif""",
    "view: reopen menu on overwrite")

print("\\nDone. Post-deploy: php artisan migrate --force && php artisan view:clear")
