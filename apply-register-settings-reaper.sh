#!/bin/bash
# apply-register-settings-reaper.sh
#
# MARKER-REG-SETTINGS — adds a Settings tab to the register screen with
# draft/quote retention controls, and a nightly sales:reap-drafts command
# that discards stale drafts (and, if enabled, stale quotes) through the
# SAME SaleService::discardDraft path the staff Discard button uses — so
# un-placed special orders are retracted identically.
#
# Scope guards on the reaper:
#   - zero ledger payments only (whereDoesntHave('payments'))
#   - appointment-linked drafts are NEVER reaped (the appointment tray
#     parks its cart as a draft; reaping one would eat a live cart)
#   - default retention is 0 = keep forever; nothing changes on deploy
#     until a tenant picks a retention window
#
# Files: RegisterController (+2 methods), routes/web.php (+2 routes),
# routes/console.php (+schedule), 3 tab bars, new command, new view.
set -e

MARKER="MARKER-REG-SETTINGS"
CTRL="app/Http/Controllers/Tenant/RegisterController.php"
WEB="routes/web.php"
CONSOLE="routes/console.php"
IDX="resources/views/tenant/register/index.blade.php"
HIST="resources/views/tenant/register/history.blade.php"
QUOT="resources/views/tenant/register/quotes.blade.php"
CMD="app/Console/Commands/SalesReapDrafts.php"
VIEW="resources/views/tenant/register/settings.blade.php"

if grep -q "$MARKER" "$CTRL" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. New command
# ---------------------------------------------------------------
cat > "$CMD" <<'EOF'
<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\SaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-REG-SETTINGS — reap stale register drafts and (optionally) quotes.
 *
 * Per tenant, driven by settings:
 *   register_draft_retention_days  (0 = keep forever, the default)
 *   register_quote_retention_days  (0 = keep forever, the default)
 *
 * Discards go through SaleService::discardDraft — the same path as the
 * staff Discard button — so un-placed special orders are retracted and
 * the semantics can never drift from the manual action.
 *
 * NEVER touched: drafts with a ledger payment, and drafts linked to an
 * appointment (the appointment tray parks its cart as a draft).
 */
class SalesReapDrafts extends Command
{
    protected $signature = 'sales:reap-drafts {--dry-run : Report what would be discarded without discarding}';
    protected $description = 'Discard register drafts (and optionally quotes) older than each tenant\'s retention setting.';

    public function handle(SaleService $sales): int
    {
        $dry = (bool) $this->option('dry-run');
        $totals = ['draft' => 0, 'quote' => 0, 'failed' => 0];

        Tenant::query()->where('is_active', true)->orderBy('id')->chunkById(50, function ($tenants) use ($sales, $dry, &$totals) {
            foreach ($tenants as $tenant) {
                $cfg = (array) ($tenant->settings ?? []);
                foreach (['draft' => 'register_draft_retention_days', 'quote' => 'register_quote_retention_days'] as $status => $key) {
                    $days = (int) ($cfg[$key] ?? 0);
                    if ($days < 1) {
                        continue; // 0 / unset = keep forever
                    }

                    $cutoff = now()->subDays($days);
                    $rows = TenantSale::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('payment_status', $status)
                        ->whereNull('appointment_id')
                        ->whereDoesntHave('payments')
                        ->where('updated_at', '<', $cutoff)
                        ->orderBy('updated_at')
                        ->limit(200) // per tenant per night; backlog drains over runs
                        ->get(['id', 'tenant_id', 'updated_at']);

                    foreach ($rows as $row) {
                        if ($dry) {
                            $this->line("would discard {$status} {$row->id} (tenant {$tenant->id}, idle since {$row->updated_at})");
                            $totals[$status]++;
                            continue;
                        }
                        try {
                            $sales->discardDraft($tenant->id, $row->id);
                            $totals[$status]++;
                        } catch (\Throwable $e) {
                            $totals['failed']++;
                            Log::warning('sales.reap_drafts_failed', [
                                'tenant_id' => $tenant->id,
                                'sale_id'   => $row->id,
                                'status'    => $status,
                                'error'     => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        });

        $verb = $dry ? 'would discard' : 'discarded';
        $this->info("sales:reap-drafts {$verb} {$totals['draft']} drafts, {$totals['quote']} quotes ({$totals['failed']} failed)");

        return self::SUCCESS;
    }
}
EOF
echo "ok: created $CMD"

# ---------------------------------------------------------------
# 2. Schedule (append; console.php has no closing brace to dodge)
# ---------------------------------------------------------------
cat >> "$CONSOLE" <<'EOF'
// ----------------------------------------------------------------
// MARKER-REG-SETTINGS — reap stale register drafts/quotes nightly,
// per each tenant's retention setting (default: keep forever).
// ----------------------------------------------------------------
Schedule::command('sales:reap-drafts')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();
EOF
echo "ok: scheduled sales:reap-drafts in $CONSOLE"

# ---------------------------------------------------------------
# 3. Routes — settings page + save, inside the retail group
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'routes/web.php'
src = io.open(p, encoding='utf-8').read()
anchor = "Route::post('/register/quotes',          [TenantControllers\\RegisterController::class, 'storeQuote'])->name('register.quotes.store');"
assert src.count(anchor) == 1, 'quotes.store anchor not found exactly once'
add = anchor + "\n                // MARKER-REG-SETTINGS -- register settings tab\n                Route::get('/register/settings',         [TenantControllers\\RegisterController::class, 'settingsPage'])->name('register.settings');\n                Route::post('/register/settings',        [TenantControllers\\RegisterController::class, 'settingsSave'])->name('register.settings.save');"
io.open(p, 'w', encoding='utf-8').write(src.replace(anchor, add, 1))
print('ok: routes added to web.php')
PY

# ---------------------------------------------------------------
# 4. Controller methods (append before final closing brace)
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/RegisterController.php'
src = io.open(p, encoding='utf-8').read()
assert 'MARKER-REG-SETTINGS' not in src
i = src.rstrip().rfind('}')
assert i > 0
methods = '''
    // MARKER-REG-SETTINGS -- register settings tab (draft/quote retention)

    public function settingsPage(Request $request, string $id)
    {
        $tenant = tenant();
        $cfg = (array) ($tenant->settings ?? []);

        return view('tenant.register.settings', [
            'tenant'         => $tenant,
            'draftRetention' => (int) ($cfg['register_draft_retention_days'] ?? 0),
            'quoteRetention' => (int) ($cfg['register_quote_retention_days'] ?? 0),
        ]);
    }

    public function settingsSave(Request $request, string $id)
    {
        $tenant = tenant();

        $data = $request->validate([
            'register_draft_retention_days' => 'required|integer|in:0,7,14,30,90',
            'register_quote_retention_days' => 'required|integer|in:0,30,90,180,365',
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $settings['register_draft_retention_days'] = (int) $data['register_draft_retention_days'];
        $settings['register_quote_retention_days'] = (int) $data['register_quote_retention_days'];
        $tenant->settings = $settings;
        $tenant->save();

        return redirect()->route('tenant.register.settings')->with('status', 'Register settings saved.');
    }
'''
src = src[:i] + methods + src[i:]
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: controller methods appended')
PY

# ---------------------------------------------------------------
# 5. New view
# ---------------------------------------------------------------
cat > "$VIEW" <<'EOF'
@extends('layouts.tenant.app')

{{-- MARKER-REG-SETTINGS -- register settings tab --}}

@php $pageTitle = 'Register settings'; @endphp

@push('styles')
<style>
  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .rs-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px 24px;margin-bottom:16px;max-width:720px}
  .rs-card h2{font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
  .rs-card .rs-desc{font-size:13px;color:var(--ia-text-dim);margin-bottom:14px;line-height:1.55}
  .rs-row{display:flex;align-items:center;gap:12px}
  .rs-row label{font-size:13px;color:var(--ia-text-muted);min-width:110px}
  .rs-links{display:flex;flex-direction:column;gap:8px}
  .rs-links a{font-size:13px;color:var(--ia-text-muted);transition:color var(--ia-t)}
  .rs-links a:hover{color:var(--ia-text)}
</style>
@endpush

@section('content')

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link active">Settings</a>
</div>

@if (session('status'))
  <div class="ia-flash ia-flash--success" style="max-width:720px">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('tenant.register.settings.save') }}">
  @csrf

  <div class="rs-card">
    <h2>Draft transactions</h2>
    <div class="rs-desc">
      Drafts are unfinished carts saved at the register. Old drafts with no payments
      are discarded automatically past this age &mdash; the same as pressing Discard,
      so any un-placed special orders they requested are retracted too. Drafts parked
      on an appointment are never touched.
    </div>
    <div class="rs-row">
      <label for="rs-draft">Keep drafts</label>
      <select id="rs-draft" name="register_draft_retention_days" class="ia-input" style="width:auto;min-width:180px">
        <option value="0"  @selected($draftRetention === 0)>Forever</option>
        <option value="7"  @selected($draftRetention === 7)>7 days</option>
        <option value="14" @selected($draftRetention === 14)>14 days</option>
        <option value="30" @selected($draftRetention === 30)>30 days</option>
        <option value="90" @selected($draftRetention === 90)>90 days</option>
      </select>
    </div>
  </div>

  <div class="rs-card">
    <h2>Quotes</h2>
    <div class="rs-desc">
      Quotes are estimates you hand a customer to think over. If you set an age here,
      quotes older than it are discarded the same way. Leave it on Forever if you
      follow up on old quotes.
    </div>
    <div class="rs-row">
      <label for="rs-quote">Keep quotes</label>
      <select id="rs-quote" name="register_quote_retention_days" class="ia-input" style="width:auto;min-width:180px">
        <option value="0"   @selected($quoteRetention === 0)>Forever</option>
        <option value="30"  @selected($quoteRetention === 30)>30 days</option>
        <option value="90"  @selected($quoteRetention === 90)>90 days</option>
        <option value="180" @selected($quoteRetention === 180)>180 days</option>
        <option value="365" @selected($quoteRetention === 365)>1 year</option>
      </select>
    </div>
  </div>

  <div style="max-width:720px;margin-bottom:24px">
    <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
  </div>
</form>

<div class="rs-card">
  <h2>More register settings</h2>
  <div class="rs-desc">These live in the main settings area:</div>
  <div class="rs-links">
    <a href="{{ route('tenant.settings.index') }}#payments">Payment methods, manual tenders &amp; card payments (Stripe) &rarr;</a>
    <a href="{{ route('tenant.settings.index') }}#tags">Receipt footer &amp; print identity &rarr;</a>
  </div>
</div>

@endsection
EOF
echo "ok: created $VIEW"

# ---------------------------------------------------------------
# 6. Tab bars — add Settings link on the three existing bars
# ---------------------------------------------------------------
python3 - <<'PY'
import io

def add_after(path, anchor, insert):
    src = io.open(path, encoding='utf-8').read()
    assert src.count(anchor) == 1, f'{path}: anchor not unique ({src.count(anchor)})'
    io.open(path, 'w', encoding='utf-8').write(src.replace(anchor, anchor + insert, 1))
    print(f'ok: tab added in {path}')

link = '\n  <a href="{{ route(\'tenant.register.settings\') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}'

add_after('resources/views/tenant/register/index.blade.php',
  '<a href="{{ route(\'tenant.register.registers\') }}" class="reg-tab-link">Registers</a> {{-- MARKER-REGISTER-RECON-DISPLAY --}}',
  link)

add_after('resources/views/tenant/register/history.blade.php',
  '<a href="{{ route(\'tenant.register.quotes.index\') }}" class="reg-tab-link">Quotes</a>',
  link)

add_after('resources/views/tenant/register/quotes.blade.php',
  '<a href="{{ route(\'tenant.register.quotes.index\') }}" class="reg-tab-link active">Quotes</a>',
  link)
PY

# ---------------------------------------------------------------
# 7. Settings page — hash deep-links (#payments, #tags, ...).
#    The tab JS was explicitly "no URL params", so external links
#    could only ever land on the Business pane.
# ---------------------------------------------------------------
python3 - <<'PY7'
import io
p = 'resources/views/tenant/settings/index.blade.php'
src = io.open(p, encoding='utf-8').read()
anchor = """  document.querySelectorAll('.set-tab').forEach(function(t) {
    t.addEventListener('click', function() { switchTab(t.dataset.tab); });
  });"""
assert src.count(anchor) == 1, 'set-tab click block not found exactly once'
add = anchor + """

  // MARKER-REG-SETTINGS -- hash deep-links: /settings#payments opens that
  // pane. Only names that match a real pane switch; anything else ignored.
  (function() {
    var h = (window.location.hash || '').replace('#', '');
    if (h && document.getElementById('pane-' + h)) switchTab(h);
  })();"""
io.open(p, 'w', encoding='utf-8').write(src.replace(anchor, add, 1))
print('ok: settings hash deep-links added')
PY7

echo ""
echo "== all edits applied =="
echo "Post-deploy: php artisan optimize:clear (view cache), then optionally"
echo "  php artisan sales:reap-drafts --dry-run   # sanity check on real data"
