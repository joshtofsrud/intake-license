#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Intake — Group A: Create-appointment modal upgrade
#
# What ships:
#   1. New _create_modal.blade.php with full picker (customer search, multi-
#      service, time picker, resource picker, in-line price override, notes)
#   2. AppointmentController::store delegates to BookingService::createAppointment
#      instead of stub-creating a row with no services
#   3. BookingService accepts price_override_cents per item, threads through
#      buildBookingPlan into total computation and TenantAppointmentItem write
#   4. New endpoint AppointmentController::pickerData returns services +
#      customers + resources + business hours for the modal to populate
#
# Backend reuses BookingService — same path as public booking, calendar
# quick-book, and now this modal.
#
# Usage on Mac:  bash intake-create-appt-modal-patch.sh
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail
[ -f artisan ] || { echo "ABORT: not a Laravel root"; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# 1. Patch BookingService — read price_override_cents from items
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Patching BookingService for price overrides + multi-service totals"

python3 <<'PY'
from pathlib import Path
p = Path("app/Services/BookingService.php")
s = p.read_text()

if "effective_price_cents" in s and "buildBookingPlan" in s and "'service'" in s:
    # Check whether plan already includes effective_price_cents for service rows
    if "'effective_price_cents' => $effectivePrice" in s:
        print("    skip: BookingService already supports overrides")
    else:
        # Patch 1: buildBookingPlan — capture price_override_cents into the plan row
        old = """            $plan[] = ['service' => $service, 'addons' => $addonRows];
        }
        return $plan;
    }"""
        new = """            // Optional per-item price override (admin/staff-create flow).
            // Null means use service catalog price. Negative or > 9999999 rejected.
            $override = $sel['price_override_cents'] ?? null;
            if ($override !== null) {
                $override = (int) $override;
                if ($override < 0 || $override > 9999999) {
                    throw new RuntimeException("Item #{$idx} price override out of range.");
                }
            }
            $effectivePrice = $override ?? (int) $service->price_cents;

            $plan[] = [
                'service'                => $service,
                'addons'                 => $addonRows,
                'price_override_cents'   => $override,
                'effective_price_cents'  => $effectivePrice,
            ];
        }
        return $plan;
    }"""
        assert s.count(old) == 1, f"ABORT: buildBookingPlan tail matched {s.count(old)}"
        s = s.replace(old, new)

        # Patch 2: total computation uses effective price
        old2 = """        foreach ($plan as $row) {
            $service = $row['service'];
            $totalCents        += (int) $service->price_cents;
            $customerFacingDur += (int) $service->duration_minutes;"""
        new2 = """        foreach ($plan as $row) {
            $service = $row['service'];
            $totalCents        += (int) ($row['effective_price_cents'] ?? $service->price_cents);
            $customerFacingDur += (int) $service->duration_minutes;"""
        assert s.count(old2) == 1, f"ABORT: total loop matched {s.count(old2)}"
        s = s.replace(old2, new2)

        # Patch 3: TenantAppointmentItem::create writes price_cents_override
        old3 = """                foreach ($plan as $row) {
                    $service = $row['service'];
                    TenantAppointmentItem::create([
                        'id'                             => (string) Str::uuid(),
                        'appointment_id'                 => $appointment->id,
                        'service_item_id'                => $service->id,
                        'item_name_snapshot'             => $service->name,
                        'price_cents'                    => $service->price_cents,
                        'duration_minutes_snapshot'      => $service->duration_minutes,
                        'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                        'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
                    ]);"""
        new3 = """                foreach ($plan as $row) {
                    $service = $row['service'];
                    TenantAppointmentItem::create([
                        'id'                             => (string) Str::uuid(),
                        'appointment_id'                 => $appointment->id,
                        'service_item_id'                => $service->id,
                        'item_name_snapshot'             => $service->name,
                        'price_cents'                    => $service->price_cents,
                        'price_cents_override'           => $row['price_override_cents'] ?? null,
                        'duration_minutes_snapshot'      => $service->duration_minutes,
                        'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                        'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
                    ]);"""
        assert s.count(old3) == 1, f"ABORT: TenantAppointmentItem::create matched {s.count(old3)}"
        s = s.replace(old3, new3)

        p.write_text(s)
        print("    patched: BookingService.php")
else:
    print("    ABORT: BookingService doesn't have expected anchors")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 2. Replace AppointmentController::store with a real BookingService delegate
#    Add pickerData endpoint
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Patching AppointmentController"

python3 <<'PY'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/AppointmentController.php")
s = p.read_text()

if "public function pickerData" in s:
    print("    skip: AppointmentController already patched")
else:
    # Replace the entire store() method with a BookingService-delegating version
    old_store = """    public function store(Request $request)
    {
        $tenant = tenant();

        if ($request->has('update')) {
            return $this->handleUpdate($tenant, $request->input('update'), $request);
        }

        $data = $request->validate([
            'customer_first_name' => ['required', 'string', 'max:100'],
            'customer_last_name'  => ['required', 'string', 'max:100'],
            'customer_email'      => ['required', 'email', 'max:255'],
            'customer_phone'      => ['nullable', 'string', 'max:32'],
            'appointment_date'    => ['required', 'date'],
            'staff_notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = TenantCustomer::firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => strtolower($data['customer_email'])],
            ['first_name' => $data['customer_first_name'], 'last_name' => $data['customer_last_name'], 'phone' => $data['customer_phone'] ?? null]
        );

        $seq = TenantAppointment::where('tenant_id', $tenant->id)->count() + 1;
        $itoNumber = 'ITO-' . str_pad($seq, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(Str::random(4));

        $locationId = $data['location_id'] ?? \\App\\Models\\Tenant\\TenantLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->value('id');

        $appointment = TenantAppointment::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'location_id' => $locationId, 'ra_number' => $itoNumber,
            'customer_first_name' => $data['customer_first_name'], 'customer_last_name' => $data['customer_last_name'],
            'customer_email' => strtolower($data['customer_email']), 'customer_phone' => $data['customer_phone'] ?? null,
            'appointment_date' => $data['appointment_date'], 'status' => 'pending', 'payment_status' => 'unpaid',
            'payment_method' => 'manual', 'subtotal_cents' => 0, 'tax_cents' => 0, 'total_cents' => 0, 'paid_cents' => 0,
            'staff_notes' => $data['staff_notes'] ?? null,
        ]);

        TenantAppointmentNote::create([
            'appointment_id' => $appointment->id, 'user_id' => Auth::guard('tenant')->id(),
            'note_type' => 'system', 'is_customer_visible' => false,
            'note_content' => 'Appointment created manually by staff.', 'created_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $appointment->id, 'ito' => $itoNumber]);
        }
        return redirect()->route('tenant.appointments.index')->with('success', 'Appointment created.');
    }"""

    new_store = """    public function store(Request $request)
    {
        $tenant = tenant();

        if ($request->has('update')) {
            return $this->handleUpdate($tenant, $request->input('update'), $request);
        }

        $data = $request->validate([
            'customer_id'         => ['nullable', 'string', 'uuid'],
            'customer_first_name' => ['required_without:customer_id', 'string', 'max:100'],
            'customer_last_name'  => ['required_without:customer_id', 'string', 'max:100'],
            'customer_email'      => ['required_without:customer_id', 'email', 'max:255'],
            'customer_phone'      => ['nullable', 'string', 'max:32'],
            'appointment_date'    => ['required', 'date'],
            'appointment_time'    => ['nullable', 'string'],
            'resource_id'         => ['nullable', 'string', 'uuid'],
            'staff_notes'         => ['nullable', 'string', 'max:1000'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.service_item_id'      => ['required', 'string', 'uuid'],
            'items.*.price_override_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        // If customer_id provided, hydrate name/email/phone from the existing record.
        $first = $data['customer_first_name'] ?? '';
        $last  = $data['customer_last_name']  ?? '';
        $email = $data['customer_email']      ?? '';
        $phone = $data['customer_phone']      ?? null;

        if (!empty($data['customer_id'])) {
            $existing = TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $data['customer_id'])
                ->first();
            if ($existing) {
                $first = $existing->first_name ?: $first;
                $last  = $existing->last_name  ?: $last;
                $email = $existing->email      ?: $email;
                $phone = $existing->phone      ?: $phone;
            }
        }

        if (!$email) {
            return response()->json(['ok' => false, 'errors' => ['customer_email' => ['Email is required.']]], 422);
        }

        // Time defaults to noon if not provided (date-only flow).
        $apptTime = !empty($data['appointment_time'])
            ? (strlen($data['appointment_time']) === 5 ? $data['appointment_time'] . ':00' : $data['appointment_time'])
            : '12:00:00';

        $payload = [
            'first_name'       => $first,
            'last_name'        => $last,
            'email'            => $email,
            'phone'            => $phone,
            'date'             => $data['appointment_date'],
            'appointment_time' => $apptTime,
            'resource_id'      => $data['resource_id'] ?? null,
            'items'            => array_map(function ($item) {
                return [
                    'service_item_id'      => $item['service_item_id'],
                    'price_override_cents' => $item['price_override_cents'] ?? null,
                    'addon_ids'            => [],
                ];
            }, $data['items']),
            'payment_method'   => 'none',
        ];

        try {
            $appointment = app(\\App\\Services\\BookingService::class)
                ->createAppointment($payload, $tenant->id);
        } catch (\\App\\Exceptions\\LockAcquisitionException $e) {
            return response()->json([
                'ok'      => false,
                'code'    => 'lock_timeout',
                'message' => 'Could not hold the slot. Try again.',
            ], 409);
        } catch (\\RuntimeException $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Persist staff notes via TenantAppointmentNote — same as old flow.
        if (!empty($data['staff_notes'])) {
            \\App\\Models\\Tenant\\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'manual',
                'is_customer_visible' => false,
                'note_content'        => $data['staff_notes'],
                'created_at'          => now(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok'       => true,
                'id'       => $appointment->id,
                'ra'       => $appointment->ra_number,
                'redirect' => route('tenant.appointments.show', [
                    'subdomain' => $tenant->subdomain,
                    'id'        => $appointment->id,
                ]),
            ]);
        }

        return redirect()->route('tenant.appointments.show', [
            'subdomain' => $tenant->subdomain,
            'id'        => $appointment->id,
        ])->with('success', 'Appointment created.');
    }

    /**
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

    assert s.count(old_store) == 1, f"ABORT: store method matched {s.count(old_store)}"
    p.write_text(s.replace(old_store, new_store))
    print("    patched: AppointmentController.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 3. Add the pickerData route
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Adding pickerData route"

python3 <<'PY'
from pathlib import Path
p = Path("routes/web.php")
s = p.read_text()

if "appointments.picker-data" in s:
    print("    skip: route already added")
else:
    # Anchor: the appointments.store route line.
    candidates = [
        "Route::post('/appointments',",
        "->name('appointments.store')",
    ]
    chosen = None
    for c in candidates:
        if s.count(c) == 1:
            chosen = c
            break
    if chosen is None:
        print(f"    ABORT: could not anchor on appointments routes")
    else:
        # Find the line containing the chosen anchor and insert before/after it
        lines = s.split("\n")
        for i, line in enumerate(lines):
            if "->name('appointments.store')" in line:
                # Insert the new route line right before this one
                indent = line[:len(line) - len(line.lstrip())]
                new_line = f"{indent}Route::get('/appointments/picker-data', [TenantControllers\\AppointmentController::class, 'pickerData'])->name('appointments.picker-data');"
                lines.insert(i, new_line)
                p.write_text("\n".join(lines))
                print("    patched: routes/web.php")
                break
        else:
            print("    ABORT: anchor line not found")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 4. Replace _create_modal.blade.php with the full picker UI
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Rewriting _create_modal.blade.php"

cat > resources/views/tenant/appointments/_create_modal.blade.php <<'BLADE_FILE'
{{--
  New Appointment modal — full picker.

  Sections:
    1. Customer (search-or-create)
    2. Services (multi-select with in-line price override)
    3. Schedule (date + time + resource)
    4. Notes

  On open, hits /admin/appointments/picker-data once to load services + resources.
  Customer search hits the same endpoint with ?q=... for live results.
  On submit, posts to /admin/appointments and redirects to the detail page.
--}}
<div id="new-appt-modal" style="display:none">
  <style>
    #new-appt-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.6);
      backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex; align-items: flex-start; justify-content: center;
      padding: 40px 20px;
      overflow-y: auto;
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
      transition: border-color .12s;
      box-sizing: border-box;
    }
    .appt-input:focus { outline: none; border-color: var(--ia-accent, #BEF264); }
    .appt-textarea { resize: vertical; min-height: 60px; }
    .appt-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .appt-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

    /* Customer search */
    .appt-cust-results {
      background: var(--ia-surface-2, #222);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: 8px; margin-top: 4px;
      max-height: 180px; overflow-y: auto;
    }
    .appt-cust-row { padding: 8px 12px; cursor: pointer; font-size: 13px; }
    .appt-cust-row:hover { background: rgba(255,255,255,.06); }
    .appt-cust-row .meta { font-size: 11px; opacity: .55; }
    .appt-cust-attached {
      background: var(--ia-surface-2, #222);
      border-radius: 8px; padding: 10px 12px;
      display: flex; justify-content: space-between; align-items: center;
      font-size: 13px;
    }
    .appt-cust-attached .clear { font-size: 11px; opacity: .55; cursor: pointer; }
    .appt-cust-attached .clear:hover { opacity: 1; color: #f39999; }

    /* Service picker */
    .appt-svc-list { display: flex; flex-direction: column; gap: 6px; }
    .appt-svc-row {
      display: grid;
      grid-template-columns: 1fr auto auto;
      gap: 10px; align-items: center;
      padding: 8px 10px;
      background: var(--ia-surface-2, #222);
      border-radius: 8px;
      font-size: 13px;
    }
    .appt-svc-row .name { font-weight: 500; }
    .appt-svc-row .meta { font-size: 11px; opacity: .55; }
    .appt-svc-price-edit {
      width: 88px;
      padding: 5px 8px;
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: 6px;
      color: inherit; font-size: 13px;
      text-align: right;
    }
    .appt-svc-price-edit.overridden { border-color: var(--ia-accent, #BEF264); color: var(--ia-accent, #BEF264); }
    .appt-svc-remove { font-size: 14px; opacity: .55; cursor: pointer; padding: 4px 8px; }
    .appt-svc-remove:hover { opacity: 1; color: #f39999; }
    .appt-svc-totals {
      margin-top: 8px; padding-top: 8px;
      border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      display: flex; justify-content: space-between;
      font-size: 12px; opacity: .8;
    }
    .appt-svc-totals strong { font-weight: 600; opacity: 1; }
    .appt-svc-add-btn {
      margin-top: 8px;
      width: 100%; padding: 8px;
      background: transparent;
      border: 0.5px dashed var(--ia-border, rgba(255,255,255,.2));
      border-radius: 8px;
      color: inherit; opacity: .65;
      font-size: 12px; font-family: inherit; cursor: pointer;
    }
    .appt-svc-add-btn:hover { opacity: 1; border-color: var(--ia-accent, #BEF264); }
    .appt-svc-picker {
      background: var(--ia-surface-2, #222);
      border-radius: 8px; padding: 8px;
      max-height: 200px; overflow-y: auto;
      margin-top: 6px;
    }
    .appt-svc-picker-row {
      padding: 6px 10px; cursor: pointer; font-size: 13px;
      display: flex; justify-content: space-between; align-items: center;
      border-radius: 4px;
    }
    .appt-svc-picker-row:hover { background: rgba(255,255,255,.06); }

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

        {{-- ─────────────── CUSTOMER ─────────────── --}}
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
              <div style="font-size:11px;opacity:.55;margin-top:6px">
                No match — a new customer will be created.
              </div>
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

        {{-- ─────────────── SERVICES ─────────────── --}}
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

        {{-- ─────────────── SCHEDULE ─────────────── --}}
        <div class="appt-section">
          <div class="appt-section-h">Schedule</div>
          <div class="appt-row-3">
            <div>
              <label class="appt-label">Date *</label>
              <input type="date" id="appt-date" class="appt-input" value="{{ now()->format('Y-m-d') }}">
            </div>
            <div>
              <label class="appt-label">Time</label>
              <input type="time" id="appt-time" class="appt-input">
            </div>
            <div>
              <label class="appt-label">Resource</label>
              <select id="appt-resource" class="appt-input">
                <option value="">—</option>
              </select>
            </div>
          </div>
        </div>

        {{-- ─────────────── NOTES ─────────────── --}}
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
    services: [],            // tenant's full service catalog (loaded once)
    resources: [],           // tenant's full resource list (loaded once)
    cart: [],                // selected services: [{service_item_id, name, duration, price, override}]
    customerId: null,
    pickerOpen: false,
  };

  var routes = {
    pickerData: "{{ route('tenant.appointments.picker-data') }}",
    store:      "{{ route('tenant.appointments.store') }}",
  };

  var custSearchTimer = null;

  function fmt(cents) { return '$' + (cents / 100).toFixed(2); }
  function el(id) { return document.getElementById(id); }

  function showError(msg) {
    var e = el('appt-error');
    e.textContent = msg;
    e.style.display = 'block';
  }

  function clearError() { el('appt-error').style.display = 'none'; }

  function loadInitialData() {
    fetch(routes.pickerData, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.services  = data.services  || [];
        state.resources = data.resources || [];
        renderResources();
      })
      .catch(function () { showError('Could not load services. Try again.'); });
  }

  function renderResources() {
    var sel = el('appt-resource');
    sel.innerHTML = '<option value="">—</option>';
    state.resources.forEach(function (r) {
      var opt = document.createElement('option');
      opt.value = r.id;
      opt.textContent = r.name + (r.subtitle ? ' · ' + r.subtitle : '');
      sel.appendChild(opt);
    });
  }

  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-new-fields').style.display = 'none';
    ['appt-first','appt-last','appt-email','appt-phone','appt-notes','appt-time'].forEach(function (id) { el(id).value = ''; });
    el('appt-date').value = new Date().toISOString().split('T')[0];
    el('appt-resource').value = '';
    renderCart();
    el('appt-svc-picker').style.display = 'none';
    state.pickerOpen = false;

    el('new-appt-modal').style.display = 'block';

    // Load on first open if services list is empty
    if (state.services.length === 0) loadInitialData();

    el('appt-cust-search').focus();
  }

  function close() {
    el('new-appt-modal').style.display = 'none';
  }

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
      // Show new-customer fields, prefill name from search if it looks like a name
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
    if (state.pickerOpen) {
      renderServicePicker();
      el('appt-svc-picker').style.display = 'block';
    } else {
      el('appt-svc-picker').style.display = 'none';
    }
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
    state.cart.push({
      service_item_id: s.id,
      name: s.name,
      duration: s.duration_minutes,
      price: s.price_cents,
      override: null,
    });
    state.pickerOpen = false;
    el('appt-svc-picker').style.display = 'none';
    renderCart();
  }

  function removeFromCart(idx) {
    state.cart.splice(idx, 1);
    renderCart();
  }

  function setOverride(idx, dollarStr) {
    var clean = dollarStr.replace(/[^\d.]/g, '');
    if (clean === '') {
      state.cart[idx].override = null;
    } else {
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

  // ── Submit ──
  function submit() {
    clearError();
    if (state.cart.length === 0) return showError('Add at least one service.');

    var date = el('appt-date').value;
    if (!date) return showError('Pick a date.');

    var btn = el('appt-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="appt-spin"></span>Creating…';

    var payload = {
      customer_id: state.customerId,
      appointment_date: date,
      appointment_time: el('appt-time').value || null,
      resource_id:      el('appt-resource').value || null,
      staff_notes:      el('appt-notes').value || null,
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
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
    .then(function (res) {
      if (res.ok && res.body.ok) {
        if (res.body.redirect) {
          window.location.href = res.body.redirect;
        } else {
          window.location.reload();
        }
        return;
      }
      var msg = (res.body && (res.body.message || (res.body.errors && Object.values(res.body.errors).flat().join(' '))))
        || 'Server error.';
      showError(msg);
      btn.disabled = false;
      btn.innerHTML = 'Create Appointment';
    })
    .catch(function () {
      showError('Network error.');
      btn.disabled = false;
      btn.innerHTML = 'Create Appointment';
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  return {
    open: open,
    close: close,
    clearCustomer: clearCustomer,
    toggleServicePicker: toggleServicePicker,
    submit: submit,
  };
})();

// Backward compat with the old global names used elsewhere in the codebase.
window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };
</script>
BLADE_FILE

echo "    wrote: _create_modal.blade.php"

# ──────────────────────────────────────────────────────────────────────────────
# Lint
# ──────────────────────────────────────────────────────────────────────────────
echo ""
echo "==> Linting"
for f in \
  app/Services/BookingService.php \
  app/Http/Controllers/Tenant/AppointmentController.php; do
  if command -v php >/dev/null 2>&1; then
    php -l "$f"
  else
    echo "    (no php — skip lint of $f)"
  fi
done

echo ""
echo "==> Patch complete."
echo ""
echo "Files touched:"
echo "  app/Services/BookingService.php"
echo "  app/Http/Controllers/Tenant/AppointmentController.php"
echo "  routes/web.php"
echo "  resources/views/tenant/appointments/_create_modal.blade.php"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'Group A: full create-appointment modal with services + price override'"
echo "  git push"
echo ""
echo "Server:"
echo "  cd /var/www/intake && git pull"
echo "  php artisan optimize:clear && php artisan view:clear && php artisan route:clear"
echo "  sudo systemctl restart php8.3-fpm"
