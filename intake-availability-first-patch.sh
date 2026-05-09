#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Intake — Availability-first appointment modal
#
# What ships:
#   1. BookingService.nextAvailableSlot + .nextAvailablePerResource (Redis 60s TTL)
#   2. AppointmentController::pickerData accepts ?service_ids[] and returns
#      computed availability (earliest + per-resource alternatives)
#   3. _create_modal.blade.php redesigned: availability-first with manual
#      override expansion, debounced re-fetch on service change
#
# Usage on Mac:  bash intake-availability-first-patch.sh
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail
[ -f artisan ] || { echo "ABORT: not a Laravel root"; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# Phase 1: BookingService
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 1: BookingService — nextAvailableSlot + nextAvailablePerResource"

python3 <<'PY'
from pathlib import Path
p = Path("app/Services/BookingService.php")
s = p.read_text()

if "nextAvailableSlot" in s:
    print("    skip: already patched")
else:
    old = """        return $slots;
    }

    /**
     * Expand break records into concrete time windows for a given date."""

    new = """        return $slots;
    }

    /**
     * Walk forward day-by-day to find the earliest available slot for a service
     * of the given duration. Optionally scoped to a specific resource. Cached
     * in Redis (60s TTL) keyed by tenant + duration + resource.
     *
     * Returns: ['date' => 'Y-m-d', 'time' => 'H:i', 'resource_id' => ?string]
     *          or null if nothing fits within $maxDaysAhead.
     */
    public function nextAvailableSlot(
        Tenant $tenant,
        int $requiredMinutes,
        ?string $resourceId = null,
        ?int $maxDaysAhead = null
    ): ?array {
        $maxDaysAhead = $maxDaysAhead ?? ($tenant->booking_window_days ?? 60);

        $cacheKey = sprintf(
            'avail:next:%s:%d:%s',
            $tenant->id,
            $requiredMinutes,
            $resourceId ?? 'any'
        );

        $cached = \\Illuminate\\Support\\Facades\\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'NULL_SENTINEL' ? null : $cached;
        }

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $earliest = now()->addHours($minNoticeHours);

        $cursor = $earliest->copy()->startOfDay();
        $stopAt = $earliest->copy()->addDays($maxDaysAhead);

        while ($cursor->lte($stopAt)) {
            $date = $cursor->toDateString();
            $slots = $this->availableSlotsForDate(
                $tenant,
                $date,
                $resourceId,
                $requiredMinutes
            );

            if ($cursor->isSameDay($earliest)) {
                $earliestTime = $earliest->format('H:i');
                $slots = array_values(array_filter($slots, fn($t) => $t >= $earliestTime));
            }

            if (!empty($slots)) {
                $result = [
                    'date'        => $date,
                    'time'        => $slots[0],
                    'resource_id' => $resourceId,
                ];
                \\Illuminate\\Support\\Facades\\Cache::put($cacheKey, $result, 60);
                return $result;
            }
            $cursor->addDay();
        }

        \\Illuminate\\Support\\Facades\\Cache::put($cacheKey, 'NULL_SENTINEL', 60);
        return null;
    }

    /**
     * Like nextAvailableSlot, but returns one entry per active resource.
     * Sorted by earliest-available first.
     */
    public function nextAvailablePerResource(
        Tenant $tenant,
        int $requiredMinutes,
        ?int $maxDaysAhead = null
    ): array {
        $resources = \\App\\Models\\Tenant\\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);

        $out = [];
        foreach ($resources as $r) {
            $slot = $this->nextAvailableSlot($tenant, $requiredMinutes, $r->id, $maxDaysAhead);
            if ($slot) {
                $out[] = [
                    'resource_id' => $r->id,
                    'name'        => $r->name,
                    'date'        => $slot['date'],
                    'time'        => $slot['time'],
                ];
            }
        }

        usort($out, function ($a, $b) {
            if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
            return strcmp($a['time'], $b['time']);
        });

        return $out;
    }

    /**
     * Expand break records into concrete time windows for a given date."""

    assert s.count(old) == 1, f"ABORT: BookingService anchor matched {s.count(old)}"
    p.write_text(s.replace(old, new))
    print("    patched: BookingService.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Phase 2: pickerData
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 2: pickerData accepts service_ids[] for availability"

python3 <<'PY'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/AppointmentController.php")
s = p.read_text()

if "nextAvailablePerResource" in s:
    print("    skip: already patched")
else:
    old = """    /**
     * JSON endpoint that powers the create-appointment modal — returns the
     * tenant's services, customers (filtered by search), and resources
     * needed to populate the picker UI.
     */
    public function pickerData(Request $request)
    {
        $tenant = tenant();
        $search = trim((string) $request->query('q', ''));

        $services = \\App\\Models\\Tenant\\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'price_cents']);

        $customersQuery = TenantCustomer::where('tenant_id', $tenant->id);
        if ($search !== '') {
            $customersQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', \"%{$search}%\")
                  ->orWhere('last_name',  'like', \"%{$search}%\")
                  ->orWhere('email',      'like', \"%{$search}%\")
                  ->orWhere('phone',      'like', \"%{$search}%\");
            });
        }
        $customers = $customersQuery
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(15)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $resources = \\App\\Models\\Tenant\\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'subtitle']);

        return response()->json([
            'services'  => $services,
            'customers' => $customers,
            'resources' => $resources,
        ]);
    }"""

    new = """    /**
     * JSON endpoint that powers the create-appointment modal.
     *
     * Modes:
     *   - Default: services + customers + resources (full picker setup)
     *   - With service_ids[]: ALSO returns next-available + per-resource
     *     alternatives via BookingService availability methods
     */
    public function pickerData(Request $request)
    {
        $tenant = tenant();
        $search = trim((string) $request->query('q', ''));

        $services = \\App\\Models\\Tenant\\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'price_cents',
                   'prep_before_minutes', 'cleanup_after_minutes']);

        $customersQuery = TenantCustomer::where('tenant_id', $tenant->id);
        if ($search !== '') {
            $customersQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', \"%{$search}%\")
                  ->orWhere('last_name',  'like', \"%{$search}%\")
                  ->orWhere('email',      'like', \"%{$search}%\")
                  ->orWhere('phone',      'like', \"%{$search}%\");
            });
        }
        $customers = $customersQuery
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(15)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $resources = \\App\\Models\\Tenant\\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'subtitle']);

        $availability = null;
        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));

        if (!empty($serviceIds)) {
            $picked = $services->whereIn('id', $serviceIds);
            $required = 0;
            foreach ($picked as $svc) {
                $required += (int) ($svc->prep_before_minutes ?? 0)
                           + (int) ($svc->duration_minutes ?? 0)
                           + (int) ($svc->cleanup_after_minutes ?? 0);
            }

            if ($required > 0) {
                $bookingService = app(\\App\\Services\\BookingService::class);
                $earliest = $bookingService->nextAvailableSlot($tenant, $required, null);
                $perResource = $bookingService->nextAvailablePerResource($tenant, $required);

                $availability = [
                    'required_minutes' => $required,
                    'earliest'         => $earliest,
                    'per_resource'     => $perResource,
                ];
            }
        }

        return response()->json([
            'services'     => $services,
            'customers'    => $customers,
            'resources'    => $resources,
            'availability' => $availability,
        ]);
    }"""

    assert s.count(old) == 1, f"ABORT: pickerData anchor matched {s.count(old)}"
    p.write_text(s.replace(old, new))
    print("    patched: AppointmentController.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Phase 3: modal redesign — write file fresh
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 3: rewriting _create_modal.blade.php"

cat > resources/views/tenant/appointments/_create_modal.blade.php <<'BLADE_FILE'
{{--
  New Appointment modal — availability-first design.

  Sections:
    1. Customer (search-or-create)
    2. Services (multi-select with in-line price override)
    3. When (NEW: next-available suggestion + alternatives + manual override)
    4. Notes

  Key differences from prior version:
    - "When" is the system's job, not the user's. Once services are picked, the
      modal asks pickerData?service_ids[]=... and surfaces the earliest slot.
    - "Pick another time" expands a manual override (date + time + resource).
    - Adding/removing services refires availability lookup (300ms debounce).
--}}
<div id="new-appt-modal" style="display:none">
  <style>
    #new-appt-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.6);
      backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex; align-items: flex-start; justify-content: center;
      padding: 40px 20px; overflow-y: auto;
      animation: appt-fade .2s ease-out;
    }
    @keyframes appt-fade { from { opacity: 0; } to { opacity: 1; } }
    #new-appt-card {
      background: var(--ia-surface, #1a1a1a);
      color: var(--ia-text, #f0f0f0);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-lg, 16px);
      width: 100%; max-width: 580px;
      animation: appt-pop .25s cubic-bezier(.2,1.1,.3,1);
    }
    @keyframes appt-pop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    .appt-head { padding: 22px 26px 0; display: flex; justify-content: space-between; align-items: center; }
    .appt-title { font-size: 20px; font-weight: 700; }
    .appt-close { background: none; border: none; color: inherit; font-size: 24px; cursor: pointer; opacity: .5; padding: 4px 8px; line-height: 1; }
    .appt-close:hover { opacity: 1; }

    .appt-body { padding: 18px 26px; }
    .appt-section { margin-bottom: 22px; }
    .appt-section-h { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; opacity: .55; margin-bottom: 10px; }

    .appt-field { margin-bottom: 12px; }
    .appt-label { display: block; font-size: 12px; opacity: .7; margin-bottom: 5px; }
    .appt-input {
      width: 100%; padding: 9px 12px;
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      color: var(--ia-text, #f0f0f0); font-size: 14px; font-family: inherit;
      transition: border-color .12s; box-sizing: border-box;
    }
    .appt-input:focus { outline: none; border-color: var(--ia-accent, #BEF264); }
    .appt-textarea { resize: vertical; min-height: 60px; }
    .appt-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .appt-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

    /* Customer search */
    .appt-cust-results { background: var(--ia-surface-2, #222); border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 8px; margin-top: 4px; max-height: 180px; overflow-y: auto; }
    .appt-cust-row { padding: 8px 12px; cursor: pointer; font-size: 13px; }
    .appt-cust-row:hover { background: rgba(255,255,255,.06); }
    .appt-cust-row .meta { font-size: 11px; opacity: .55; }
    .appt-cust-attached { background: var(--ia-surface-2, #222); border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .appt-cust-attached .clear { font-size: 11px; opacity: .55; cursor: pointer; }
    .appt-cust-attached .clear:hover { opacity: 1; color: #f39999; }

    /* Service picker */
    .appt-svc-list { display: flex; flex-direction: column; gap: 6px; }
    .appt-svc-row { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; padding: 8px 10px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 13px; }
    .appt-svc-row .name { font-weight: 500; }
    .appt-svc-row .meta { font-size: 11px; opacity: .55; }
    .appt-svc-price-edit { width: 88px; padding: 5px 8px; background: rgba(255,255,255,.04); border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 6px; color: inherit; font-size: 13px; text-align: right; }
    .appt-svc-price-edit.overridden { border-color: var(--ia-accent, #BEF264); color: var(--ia-accent, #BEF264); }
    .appt-svc-remove { font-size: 14px; opacity: .55; cursor: pointer; padding: 4px 8px; }
    .appt-svc-remove:hover { opacity: 1; color: #f39999; }
    .appt-svc-totals { margin-top: 8px; padding-top: 8px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); display: flex; justify-content: space-between; font-size: 12px; opacity: .8; }
    .appt-svc-totals strong { font-weight: 600; opacity: 1; }
    .appt-svc-add-btn { margin-top: 8px; width: 100%; padding: 8px; background: transparent; border: 0.5px dashed var(--ia-border, rgba(255,255,255,.2)); border-radius: 8px; color: inherit; opacity: .65; font-size: 12px; font-family: inherit; cursor: pointer; }
    .appt-svc-add-btn:hover { opacity: 1; border-color: var(--ia-accent, #BEF264); }
    .appt-svc-picker { background: var(--ia-surface-2, #222); border-radius: 8px; padding: 8px; max-height: 200px; overflow-y: auto; margin-top: 6px; }
    .appt-svc-picker-row { padding: 6px 10px; cursor: pointer; font-size: 13px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px; }
    .appt-svc-picker-row:hover { background: rgba(255,255,255,.06); }

    /* Availability section */
    .appt-when-empty { padding: 14px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 12px; opacity: .55; text-align: center; }
    .appt-when-loading { padding: 14px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 12px; opacity: .65; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .appt-when-card {
      padding: 14px;
      background: rgba(190, 242, 100, 0.08);
      border: 0.5px solid var(--ia-accent, #BEF264);
      border-radius: 8px;
      margin-bottom: 8px;
    }
    .appt-when-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
    .appt-when-card-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--ia-accent, #BEF264); }
    .appt-when-card-pick { font-size: 11px; color: var(--ia-accent, #BEF264); cursor: pointer; opacity: .85; }
    .appt-when-card-pick:hover { opacity: 1; }
    .appt-when-card-time { font-size: 15px; font-weight: 500; color: var(--ia-text, #f0f0f0); }
    .appt-when-none { padding: 14px; background: rgba(226,75,74,.10); border: 0.5px solid rgba(226,75,74,.25); border-radius: 8px; font-size: 13px; color: #f39999; }
    .appt-when-alts { margin-top: 10px; }
    .appt-when-alts-label { font-size: 11px; opacity: .55; margin-bottom: 6px; }
    .appt-when-alt-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 8px; cursor: pointer; margin-bottom: 4px; font-size: 13px; }
    .appt-when-alt-row:hover { border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-row.selected { background: rgba(190, 242, 100, 0.08); border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-time { font-size: 12px; opacity: .85; }
    .appt-when-manual-toggle { font-size: 11px; color: var(--ia-text-muted, #999); cursor: pointer; margin-top: 10px; display: inline-block; }
    .appt-when-manual-toggle:hover { color: var(--ia-text, #f0f0f0); }
    .appt-when-manual { margin-top: 10px; padding-top: 10px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); }

    .appt-foot { padding: 16px 26px 22px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); display: flex; justify-content: flex-end; gap: 10px; }
    .appt-btn { padding: 10px 18px; border-radius: var(--ia-r-md, 8px); font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; border: none; transition: filter .12s; }
    .appt-btn--cancel { background: rgba(255,255,255,.06); color: var(--ia-text, #f0f0f0); }
    .appt-btn--create { background: var(--ia-accent, #BEF264); color: #000; }
    .appt-btn:hover { filter: brightness(.92); }
    .appt-btn:disabled { opacity: .5; cursor: not-allowed; }
    .appt-err { background: rgba(226,75,74,.12); color: #f39999; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 12px; display: none; }
    .appt-spin { display: inline-block; width: 12px; height: 12px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: appt-spin .6s linear infinite; vertical-align: -2px; margin-right: 6px; }
    @keyframes appt-spin { to { transform: rotate(360deg); } }
  </style>

  <div id="new-appt-backdrop">
    <div id="new-appt-card">
      <div class="appt-head">
        <span class="appt-title">New Appointment</span>
        <button type="button" class="appt-close" onclick="ApptModal.close()">&times;</button>
      </div>

      <div class="appt-body">
        <div id="appt-error" class="appt-err"></div>

        {{-- Customer --}}
        <div class="appt-section">
          <div class="appt-section-h">Customer</div>
          <div id="appt-cust-search-wrap">
            <input type="search" id="appt-cust-search" class="appt-input" placeholder="Search by name, email, or phone…" autocomplete="off">
            <div id="appt-cust-results" class="appt-cust-results" style="display:none"></div>
            <div id="appt-cust-new-fields" style="display:none; margin-top:10px">
              <div class="appt-row">
                <input type="text" id="appt-first" class="appt-input" placeholder="First name *">
                <input type="text" id="appt-last"  class="appt-input" placeholder="Last name *">
              </div>
              <div class="appt-row" style="margin-top:8px">
                <input type="email" id="appt-email" class="appt-input" placeholder="Email *">
                <input type="tel"   id="appt-phone" class="appt-input" placeholder="Phone">
              </div>
              <div style="font-size:11px;opacity:.55;margin-top:6px">No match — a new customer will be created.</div>
            </div>
          </div>
          <div id="appt-cust-attached" class="appt-cust-attached" style="display:none">
            <div>
              <div id="appt-cust-attached-name" style="font-weight:500"></div>
              <div id="appt-cust-attached-meta" style="font-size:11px;opacity:.55"></div>
            </div>
            <span class="clear" onclick="ApptModal.clearCustomer()">Remove</span>
          </div>
        </div>

        {{-- Services --}}
        <div class="appt-section">
          <div class="appt-section-h">Services</div>
          <div id="appt-svc-list" class="appt-svc-list"></div>
          <button type="button" id="appt-svc-add-btn" class="appt-svc-add-btn" onclick="ApptModal.toggleServicePicker()">+ Add a service</button>
          <div id="appt-svc-picker" class="appt-svc-picker" style="display:none"></div>
          <div id="appt-svc-totals" class="appt-svc-totals" style="display:none">
            <span><span id="appt-svc-count">0 services</span> · <span id="appt-svc-duration">0 min</span></span>
            <strong id="appt-svc-total">$0.00</strong>
          </div>
        </div>

        {{-- When (availability-first) --}}
        <div class="appt-section">
          <div class="appt-section-h">When</div>
          <div id="appt-when-content">
            <div class="appt-when-empty">Add a service to see available times.</div>
          </div>
        </div>

        {{-- Notes --}}
        <div class="appt-section">
          <div class="appt-section-h">Staff Notes (optional)</div>
          <textarea id="appt-notes" class="appt-input appt-textarea" placeholder="Internal notes about this appointment…"></textarea>
        </div>
      </div>

      <div class="appt-foot">
        <button type="button" class="appt-btn appt-btn--cancel" onclick="ApptModal.close()">Cancel</button>
        <button type="button" class="appt-btn appt-btn--create" id="appt-submit" onclick="ApptModal.submit()">Create Appointment</button>
      </div>
    </div>
  </div>
</div>

<script>
window.ApptModal = (function () {
  var state = {
    services: [],
    resources: [],
    cart: [],
    customerId: null,
    pickerOpen: false,
    // Availability state
    availability: null,
    availLoading: false,
    selectedSlot: null,        // {date, time, resource_id}
    manualOverride: false,
    // Manual override fields (read at submit if manualOverride is true)
  };

  var routes = {
    pickerData: "{{ route('tenant.appointments.picker-data') }}",
    store:      "{{ route('tenant.appointments.store') }}",
  };

  var custSearchTimer = null;
  var availTimer = null;

  function fmt(cents) { return '$' + (cents / 100).toFixed(2); }
  function el(id) { return document.getElementById(id); }

  function showError(msg) { var e = el('appt-error'); e.textContent = msg; e.style.display = 'block'; }
  function clearError() { el('appt-error').style.display = 'none'; }

  function loadInitialData() {
    fetch(routes.pickerData, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.services  = data.services  || [];
        state.resources = data.resources || [];
      })
      .catch(function () { showError('Could not load services. Try again.'); });
  }

  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.availability = null;
    state.selectedSlot = null;
    state.manualOverride = false;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-new-fields').style.display = 'none';
    ['appt-first','appt-last','appt-email','appt-phone','appt-notes'].forEach(function (id) { el(id).value = ''; });
    renderCart();
    renderAvailability();
    el('appt-svc-picker').style.display = 'none';
    state.pickerOpen = false;
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    el('appt-cust-search').focus();
  }

  function close() { el('new-appt-modal').style.display = 'none'; }

  // ── Customer search ──
  el('appt-cust-search').addEventListener('input', function () {
    clearTimeout(custSearchTimer);
    var q = this.value.trim();
    if (q.length < 2) {
      el('appt-cust-results').style.display = 'none';
      el('appt-cust-new-fields').style.display = 'none';
      return;
    }
    custSearchTimer = setTimeout(function () {
      fetch(routes.pickerData + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderCustomerResults(data.customers || [], q); });
    }, 250);
  });

  function renderCustomerResults(customers, query) {
    var box = el('appt-cust-results');
    if (customers.length === 0) {
      box.style.display = 'none';
      el('appt-cust-new-fields').style.display = 'block';
      var parts = query.split(/\s+/);
      if (parts.length >= 2 && !query.includes('@') && !/\d/.test(query)) {
        el('appt-first').value = parts[0];
        el('appt-last').value = parts.slice(1).join(' ');
      }
      return;
    }
    box.innerHTML = '';
    customers.forEach(function (c) {
      var row = document.createElement('div');
      row.className = 'appt-cust-row';
      row.innerHTML = '<div>' + escapeHtml(c.first_name + ' ' + c.last_name) + '</div>'
        + '<div class="meta">' + escapeHtml(c.email || c.phone || '') + '</div>';
      row.addEventListener('click', function () { attachCustomer(c); });
      box.appendChild(row);
    });
    box.style.display = 'block';
    el('appt-cust-new-fields').style.display = 'none';
  }

  function attachCustomer(c) {
    state.customerId = c.id;
    el('appt-cust-attached-name').textContent = (c.first_name + ' ' + c.last_name).trim();
    el('appt-cust-attached-meta').textContent = c.email || c.phone || '';
    el('appt-cust-attached').style.display = 'flex';
    el('appt-cust-search-wrap').style.display = 'none';
  }

  function clearCustomer() {
    state.customerId = null;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-search').focus();
  }

  // ── Service picker ──
  function toggleServicePicker() {
    state.pickerOpen = !state.pickerOpen;
    if (state.pickerOpen) { renderServicePicker(); el('appt-svc-picker').style.display = 'block'; }
    else { el('appt-svc-picker').style.display = 'none'; }
  }

  function renderServicePicker() {
    var box = el('appt-svc-picker');
    if (state.services.length === 0) {
      box.innerHTML = '<div style="padding:8px;font-size:12px;opacity:.55">No services available.</div>';
      return;
    }
    box.innerHTML = '';
    state.services.forEach(function (s) {
      var row = document.createElement('div');
      row.className = 'appt-svc-picker-row';
      row.innerHTML = '<span>' + escapeHtml(s.name) + '</span>'
        + '<span style="opacity:.6;font-size:11px">' + s.duration_minutes + ' min · ' + fmt(s.price_cents) + '</span>';
      row.addEventListener('click', function () { addServiceToCart(s); });
      box.appendChild(row);
    });
  }

  function addServiceToCart(s) {
    state.cart.push({ service_item_id: s.id, name: s.name, duration: s.duration_minutes, price: s.price_cents, override: null });
    state.pickerOpen = false;
    el('appt-svc-picker').style.display = 'none';
    renderCart();
    scheduleAvailabilityFetch();
  }

  function removeFromCart(idx) {
    state.cart.splice(idx, 1);
    renderCart();
    scheduleAvailabilityFetch();
  }

  function setOverride(idx, dollarStr) {
    var clean = dollarStr.replace(/[^\d.]/g, '');
    if (clean === '') { state.cart[idx].override = null; }
    else {
      var cents = Math.round(parseFloat(clean) * 100);
      if (isNaN(cents)) cents = null;
      state.cart[idx].override = cents;
    }
    renderTotals();
  }

  function renderCart() {
    var list = el('appt-svc-list');
    if (state.cart.length === 0) {
      list.innerHTML = '<div style="font-size:12px;opacity:.5;padding:6px 0">No services selected.</div>';
      el('appt-svc-totals').style.display = 'none';
      return;
    }
    list.innerHTML = '';
    state.cart.forEach(function (line, idx) {
      var effective = line.override !== null ? line.override : line.price;
      var displayValue = (effective / 100).toFixed(2);
      var overridden = line.override !== null && line.override !== line.price;
      var row = document.createElement('div');
      row.className = 'appt-svc-row';
      row.innerHTML = '<div>'
        + '<div class="name">' + escapeHtml(line.name) + '</div>'
        + '<div class="meta">' + line.duration + ' min · catalog ' + fmt(line.price) + (overridden ? ' · <span style="color:#BEF264">overridden</span>' : '') + '</div>'
        + '</div>'
        + '<input type="text" class="appt-svc-price-edit ' + (overridden ? 'overridden' : '') + '" value="' + displayValue + '" data-idx="' + idx + '">'
        + '<span class="appt-svc-remove" data-idx="' + idx + '">&times;</span>';
      list.appendChild(row);
    });
    list.querySelectorAll('.appt-svc-price-edit').forEach(function (input) {
      input.addEventListener('change', function () { setOverride(parseInt(this.dataset.idx, 10), this.value); });
      input.addEventListener('blur',   function () { renderCart(); });
    });
    list.querySelectorAll('.appt-svc-remove').forEach(function (x) {
      x.addEventListener('click', function () { removeFromCart(parseInt(this.dataset.idx, 10)); });
    });
    renderTotals();
  }

  function renderTotals() {
    var total = 0, dur = 0;
    state.cart.forEach(function (line) {
      total += (line.override !== null ? line.override : line.price);
      dur   += line.duration;
    });
    el('appt-svc-count').textContent = state.cart.length + ' service' + (state.cart.length === 1 ? '' : 's');
    el('appt-svc-duration').textContent = dur + ' min';
    el('appt-svc-total').textContent = fmt(total);
    el('appt-svc-totals').style.display = 'flex';
  }

  // ── Availability ──
  function scheduleAvailabilityFetch() {
    clearTimeout(availTimer);
    if (state.cart.length === 0) {
      state.availability = null;
      state.selectedSlot = null;
      state.manualOverride = false;
      renderAvailability();
      return;
    }
    state.availLoading = true;
    state.manualOverride = false;
    renderAvailability();
    availTimer = setTimeout(fetchAvailability, 300);
  }

  function fetchAvailability() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&');
    fetch(routes.pickerData + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.availability = data.availability || null;
        state.availLoading = false;
        // Default-pick the suggested earliest slot.
        if (state.availability && state.availability.earliest) {
          state.selectedSlot = state.availability.earliest;
        } else {
          state.selectedSlot = null;
        }
        renderAvailability();
      })
      .catch(function () {
        state.availLoading = false;
        showError('Could not load availability.');
      });
  }

  function renderAvailability() {
    var box = el('appt-when-content');

    if (state.cart.length === 0) {
      box.innerHTML = '<div class="appt-when-empty">Add a service to see available times.</div>';
      return;
    }
    if (state.availLoading) {
      box.innerHTML = '<div class="appt-when-loading"><span class="appt-spin"></span>Finding the next available slot…</div>';
      return;
    }
    if (!state.availability || !state.availability.earliest) {
      box.innerHTML = '<div class="appt-when-none">No availability found in the next 60 days. Pick a custom time below.</div>'
        + renderManualBlock(true);
      wireManualHandlers();
      return;
    }

    var earliest = state.availability.earliest;
    var per = state.availability.per_resource || [];
    var resourceMap = {};
    state.resources.forEach(function (r) { resourceMap[r.id] = r; });

    // Resolve resource name for the earliest slot
    var earliestResourceName = earliest.resource_id && resourceMap[earliest.resource_id]
      ? resourceMap[earliest.resource_id].name
      : 'Any resource';

    // Find which resource id will actually serve this any-resource slot.
    // The earliest is computed with resourceId=null, so we need to resolve
    // which specific resource has that exact slot — pick the first per_resource
    // entry that matches the earliest date+time.
    var resolvedResourceId = earliest.resource_id;
    var resolvedResourceName = earliestResourceName;
    if (!resolvedResourceId) {
      var match = per.find(function (p) { return p.date === earliest.date && p.time === earliest.time; });
      if (match) {
        resolvedResourceId = match.resource_id;
        resolvedResourceName = match.name;
      } else if (per.length > 0) {
        // Fallback: nobody matches exactly, but resources are listed — take the soonest
        resolvedResourceId = per[0].resource_id;
        resolvedResourceName = per[0].name;
      }
    }

    var html = '<div class="appt-when-card" id="appt-when-suggested">'
      + '<div class="appt-when-card-head">'
      +   '<span class="appt-when-card-label">Next available</span>'
      +   '<span class="appt-when-card-pick" id="appt-when-pick-other">Pick another time →</span>'
      + '</div>'
      + '<div class="appt-when-card-time">' + formatSlotLabel(earliest.date, earliest.time) + ' · ' + escapeHtml(resolvedResourceName) + '</div>'
      + '</div>';

    // Show alternatives if any are sooner than the earliest, OR top 3 anyway
    var alts = per.filter(function (p) {
      return !(p.date === earliest.date && p.time === earliest.time && p.resource_id === resolvedResourceId);
    });
    if (alts.length > 0) {
      html += '<div class="appt-when-alts">';
      html += '<div class="appt-when-alts-label">Or with a different resource:</div>';
      alts.slice(0, 3).forEach(function (a) {
        html += '<div class="appt-when-alt-row" data-resource="' + escapeHtml(a.resource_id)
          + '" data-date="' + escapeHtml(a.date) + '" data-time="' + escapeHtml(a.time) + '">'
          + '<span>' + escapeHtml(a.name) + '</span>'
          + '<span class="appt-when-alt-time">' + formatSlotLabel(a.date, a.time) + '</span>'
          + '</div>';
      });
      html += '</div>';
    }

    if (state.manualOverride) {
      html += renderManualBlock(false);
    }

    box.innerHTML = html;

    // Save the resolved slot so submit knows which resource to send.
    state.selectedSlot = {
      date: earliest.date,
      time: earliest.time,
      resource_id: resolvedResourceId,
    };

    // Wire alt rows
    box.querySelectorAll('.appt-when-alt-row').forEach(function (row) {
      row.addEventListener('click', function () {
        state.selectedSlot = {
          date: row.dataset.date,
          time: row.dataset.time,
          resource_id: row.dataset.resource,
        };
        // Visual: re-render with the new selection highlighted
        box.querySelectorAll('.appt-when-alt-row').forEach(function (r) { r.classList.remove('selected'); });
        row.classList.add('selected');
        // Also dim the suggested card so it's clear this is the active pick
        el('appt-when-suggested').style.opacity = '.65';
      });
    });

    // Wire "Pick another time"
    var pickOther = el('appt-when-pick-other');
    if (pickOther) {
      pickOther.addEventListener('click', function () {
        state.manualOverride = true;
        renderAvailability();
      });
    }

    if (state.manualOverride) wireManualHandlers();
  }

  function renderManualBlock(isOnlyOption) {
    var defaultDate = state.selectedSlot ? state.selectedSlot.date : new Date().toISOString().split('T')[0];
    var defaultTime = state.selectedSlot ? state.selectedSlot.time : '';
    var defaultResource = state.selectedSlot ? state.selectedSlot.resource_id : '';
    var resourceOpts = '<option value="">Pick a resource…</option>';
    state.resources.forEach(function (r) {
      resourceOpts += '<option value="' + escapeHtml(r.id) + '"' + (defaultResource === r.id ? ' selected' : '') + '>'
        + escapeHtml(r.name + (r.subtitle ? ' · ' + r.subtitle : '')) + '</option>';
    });

    return '<div class="appt-when-manual">'
      + (isOnlyOption ? '' : '<div style="font-size:11px;opacity:.55;margin-bottom:8px">Manual override:</div>')
      + '<div class="appt-row-3">'
      +   '<div><label class="appt-label">Date *</label><input type="date" id="appt-manual-date" class="appt-input" value="' + escapeHtml(defaultDate) + '"></div>'
      +   '<div><label class="appt-label">Time *</label><input type="time" id="appt-manual-time" class="appt-input" value="' + escapeHtml(defaultTime) + '"></div>'
      +   '<div><label class="appt-label">Resource</label><select id="appt-manual-resource" class="appt-input">' + resourceOpts + '</select></div>'
      + '</div>'
      + '</div>';
  }

  function wireManualHandlers() {
    ['appt-manual-date', 'appt-manual-time', 'appt-manual-resource'].forEach(function (id) {
      var node = el(id);
      if (node) node.addEventListener('change', function () {
        state.selectedSlot = {
          date: el('appt-manual-date').value,
          time: el('appt-manual-time').value,
          resource_id: el('appt-manual-resource').value || null,
        };
      });
    });
  }

  function formatSlotLabel(date, time) {
    // date: YYYY-MM-DD, time: HH:MM
    var d = new Date(date + 'T' + time);
    var dateStr = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    var timeStr = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    return dateStr + ' at ' + timeStr;
  }

  // ── Submit ──
  function submit() {
    clearError();
    if (state.cart.length === 0) return showError('Add at least one service.');
    if (!state.selectedSlot || !state.selectedSlot.date) return showError('Pick a time.');

    var btn = el('appt-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="appt-spin"></span>Creating…';

    var payload = {
      customer_id: state.customerId,
      appointment_date: state.selectedSlot.date,
      appointment_time: state.selectedSlot.time || null,
      resource_id: state.selectedSlot.resource_id || null,
      staff_notes: el('appt-notes').value || null,
      items: state.cart.map(function (l) {
        return {
          service_item_id: l.service_item_id,
          price_override_cents: l.override !== null && l.override !== l.price ? l.override : null,
        };
      }),
    };
    if (!state.customerId) {
      payload.customer_first_name = el('appt-first').value.trim();
      payload.customer_last_name  = el('appt-last').value.trim();
      payload.customer_email      = el('appt-email').value.trim();
      payload.customer_phone      = el('appt-phone').value.trim();
      if (!payload.customer_first_name || !payload.customer_last_name || !payload.customer_email) {
        showError('First name, last name, and email are required for a new customer.');
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
    }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    fetch(routes.store, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : '' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
    .then(function (res) {
      if (res.ok && res.body.ok) {
        if (res.body.redirect) window.location.href = res.body.redirect;
        else window.location.reload();
        return;
      }
      // If the slot got taken between fetch and submit, refresh availability.
      if (res.body && res.body.code === 'lock_timeout') {
        showError('That slot was just taken. Recomputing…');
        scheduleAvailabilityFetch();
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
      var msg = (res.body && (res.body.message || (res.body.errors && Object.values(res.body.errors).flat().join(' ')))) || 'Server error.';
      showError(msg);
      btn.disabled = false; btn.innerHTML = 'Create Appointment';
    })
    .catch(function () {
      showError('Network error.');
      btn.disabled = false; btn.innerHTML = 'Create Appointment';
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  return {
    open: open, close: close, clearCustomer: clearCustomer,
    toggleServicePicker: toggleServicePicker, submit: submit,
  };
})();

window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };
</script>
BLADE_FILE
echo "    wrote: _create_modal.blade.php"

echo ""
echo "==> Linting"
for f in app/Services/BookingService.php app/Http/Controllers/Tenant/AppointmentController.php; do
  if command -v php >/dev/null 2>&1; then php -l "$f"; else echo "    (no php — skip lint $f)"; fi
done

echo ""
echo "==> Patch complete."
echo ""
echo "Files touched:"
echo "  app/Services/BookingService.php"
echo "  app/Http/Controllers/Tenant/AppointmentController.php"
echo "  resources/views/tenant/appointments/_create_modal.blade.php"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'Availability-first appointment modal with Redis caching'"
echo "  git push"
echo ""
echo "Server:"
echo "  cd /var/www/intake && git pull"
echo "  php artisan optimize:clear && php artisan view:clear && php artisan cache:clear"
echo "  sudo systemctl restart php8.3-fpm"
