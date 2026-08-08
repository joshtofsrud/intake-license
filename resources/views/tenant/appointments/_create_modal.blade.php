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

    /* Day strip picker */
    .appt-strip-wrap { display: flex; align-items: center; gap: 4px; margin-bottom: 12px; }
    .appt-strip-arrow { font-size: 18px; opacity: .5; cursor: pointer; padding: 4px 8px; user-select: none; }
    .appt-strip-arrow:hover { opacity: 1; }
    .appt-strip-arrow.disabled { opacity: .2; cursor: not-allowed; }
    .appt-strip { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; flex: 1; }
    .appt-strip-day {
      text-align: center;
      padding: 8px 4px;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-strip-day:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-strip-day.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
    }
    .appt-strip-day.disabled { opacity: .35; cursor: not-allowed; }
    .appt-strip-day.disabled:hover { border-color: transparent; }
    .appt-strip-dow { font-size: 10px; text-transform: uppercase; opacity: .55; letter-spacing: .04em; }
    .appt-strip-num { font-size: 14px; font-weight: 500; margin: 1px 0; }
    .appt-strip-meta { font-size: 9px; opacity: .55; }
    .appt-strip-day.selected .appt-strip-dow,
    .appt-strip-day.selected .appt-strip-meta { color: var(--ia-accent, #BEF264); opacity: 1; }
    .appt-strip-day.selected .appt-strip-num { color: var(--ia-accent, #BEF264); }

    .appt-times-label { font-size: 11px; opacity: .55; margin-bottom: 6px; }
    .appt-times-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
    .appt-time-btn {
      padding: 8px 4px;
      text-align: center;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      font-size: 13px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-time-btn:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-time-btn.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
      color: var(--ia-accent, #BEF264);
      font-weight: 500;
    }
    .appt-times-empty { font-size: 12px; opacity: .55; padding: 12px; text-align: center; background: var(--ia-surface-2, #222); border-radius: 6px; }
    .appt-resolved-resource { font-size: 11px; opacity: .65; margin-top: 10px; }
    .appt-resolved-resource a { color: var(--ia-accent, #BEF264); cursor: pointer; }

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
    .appt-when-alts-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
    .appt-when-alts-label { font-size: 11px; opacity: .55; }
    .appt-when-alts-nav { display: flex; gap: 6px; }
    .appt-when-alts-arrow { width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; border-radius: 4px; background: rgba(255,255,255,.04); cursor: pointer; font-size: 14px; opacity: .65; user-select: none; }
    .appt-when-alts-arrow:hover { opacity: 1; background: rgba(255,255,255,.08); }
    .appt-when-alts-arrow.disabled { opacity: .2; cursor: not-allowed; }
    .appt-when-alts-track { display: flex; gap: 6px; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; -ms-overflow-style: none; scroll-behavior: smooth; }
    .appt-when-alts-track::-webkit-scrollbar { display: none; }
    .appt-when-alt-row { flex: 0 0 calc((100% - 12px) / 3); scroll-snap-align: start; display: flex; flex-direction: column; justify-content: center; gap: 3px; padding: 10px 12px; border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 8px; cursor: pointer; font-size: 13px; min-height: 52px; box-sizing: border-box; }
    .appt-when-alt-row:hover { border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-row.selected { background: rgba(190, 242, 100, 0.08); border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .appt-when-alt-time { font-size: 11px; opacity: .65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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
    
    /* SEQUENTIAL-PICKER-CSS v1 */
    .appt-sp-times-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px; flex-wrap:wrap; }
    .appt-sp-week-nav { display:flex; align-items:center; gap:6px; font-size:11px; }
    .appt-sp-week-btn {
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      color: inherit;
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 4px;
      cursor: pointer;
      font-family: inherit;
    }
    .appt-sp-week-btn:hover:not(:disabled) { background: rgba(255,255,255,.08); }
    .appt-sp-week-btn:disabled { opacity: .35; cursor: not-allowed; }
    .appt-sp-week-label { opacity: .65; min-width: 100px; text-align: center; }
    .appt-sp-times-list {
      max-height: 240px;       /* ~5 rows visible */
      overflow-y: auto;
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: 8px;
      background: rgba(255,255,255,.02);
    }
    .appt-sp-time-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 14px;
      border-bottom: 0.5px solid var(--ia-border, rgba(255,255,255,.06));
      cursor: pointer;
      font-size: 13px;
    }
    .appt-sp-time-row:last-child { border-bottom: none; }
    .appt-sp-time-row:hover { background: rgba(190,242,100,0.06); }
    .appt-sp-time-row.selected { background: rgba(190,242,100,0.12); border-left: 2px solid var(--ia-accent, #BEF264); padding-left: 12px; }
    .appt-sp-time-date { opacity: .65; font-size: 12px; }
    .appt-sp-time-time { font-weight: 500; }
    .appt-sp-times-empty {
      padding: 18px 14px;
      text-align: center;
      font-size: 12px;
      opacity: .55;
    }
    .appt-sp-times-empty.error { color: #f39999; opacity: .8; }
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

        {{-- MARKER-APPT-ASSET — single-asset select/create; multi lives on the work order page --}}
        @if($currentTenant->multi_asset_enabled)
        @php $aSing = strtolower($currentTenant->asset_label_singular ?: 'item'); $aPlur = strtolower($currentTenant->asset_label_plural ?: ($aSing.'s')); @endphp
        <div class="appt-section" id="appt-asset-section">
          <div class="appt-section-h">{{ ucfirst($aSing) }}</div>
          <div id="appt-asset-need-customer" style="font-size:11px;opacity:.55">Choose a customer to pick or add a {{ $aSing }}.</div>
          <select id="appt-asset-select" class="appt-input" style="display:none"
                  onchange="var n=document.getElementById('appt-asset-new'); if(n) n.style.display=(this.value==='__new__'?'block':'none');"></select>
          <div id="appt-asset-new" style="display:none; margin-top:8px">
            <div class="appt-row">
              <input type="text" id="appt-asset-name" class="appt-input" placeholder="Make &amp; model">
              <input type="text" id="appt-asset-id"   class="appt-input" placeholder="Serial (optional)">
            </div>
            <div style="font-size:11px;opacity:.55;margin-top:6px">Saved to the customer for next time.</div>
          </div>
          <p style="font-size:11px;opacity:.55;margin-top:8px">For multi-{{ $aPlur }} work orders, create the appointment and add {{ $aPlur }} on the work order screen.</p>
        </div>
        @endif

        {{-- SEQUENTIAL-PICKER v1 --}}
        <div class="appt-section">
          <div class="appt-section-h">Service</div>
          {{-- SERVICE-TYPEAHEAD v1 — register-style search over the loaded catalog --}}
          <div id="appt-sp-service-wrap" style="position:relative">
            <input type="text" id="appt-sp-service-search" class="appt-input"
                   placeholder="Search services…" autocomplete="off">
            <div id="appt-sp-service-results" class="appt-cust-results" style="display:none"></div>
          </div>
          <div id="appt-sp-service-selected" class="appt-cust-attached" style="display:none">
            <div>
              <div id="appt-sp-service-selected-name" style="font-weight:500"></div>
              <div id="appt-sp-service-selected-meta" style="font-size:11px;opacity:.55"></div>
            </div>
            <span class="clear" onclick="ApptModal.clearService()">Change</span>
          </div>
          <p class="appt-sp-note" style="font-size:11px; opacity:.55; margin-top:6px;">
            You can add more services on the next page after creating the appointment.
          </p>
        </div>

        <div class="appt-section" id="appt-sp-resource-section" style="display:none">
          <div class="appt-section-h">Resource</div>
          <select id="appt-sp-resource" class="appt-input">
            <option value="">Select a resource…</option>
          </select>
        </div>

        <div class="appt-section" id="appt-sp-find-section" style="display:none">
          <button type="button" class="appt-btn appt-btn--cancel" id="appt-sp-find" style="width:100%; padding:10px;">
            Show available times
          </button>
        </div>

        <div class="appt-section" id="appt-sp-times-section" style="display:none">
          <div class="appt-sp-times-head">
            <div class="appt-section-h" style="margin-bottom:0">Available times</div>
            <div class="appt-sp-week-nav">
              <button type="button" class="appt-sp-week-btn" id="appt-sp-prev-week" disabled>← Prev week</button>
              <span class="appt-sp-week-label" id="appt-sp-week-label">—</span>
              <button type="button" class="appt-sp-week-btn" id="appt-sp-next-week">Next week →</button>
            </div>
          </div>
          <div class="appt-sp-times-list" id="appt-sp-times-list">
            <div class="appt-sp-times-empty">Loading…</div>
          </div>
        </div>

        {{-- Notes --}}
        <div class="appt-section">
          <div class="appt-section-h">Staff Notes (optional)</div>
          <textarea id="appt-notes" class="appt-input appt-textarea" placeholder="Internal notes about this appointment…"></textarea>
        </div>

        {{-- MARKER-PATCH-519 — pickup window + need-by (route tenants only) --}}
        @php
          $pdModalWindows = $currentTenant->deliveries_enabled
              ? \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $currentTenant->id)->active()->get()
              : collect();
        @endphp
        @if($pdModalWindows->isNotEmpty())
        <div id="appt-pd-wrap" style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:1;min-width:180px">
            <label style="display:block;font-size:11px;color:var(--ia-text-muted);margin-bottom:4px">Pickup window <span style="opacity:.6">(optional — picks the chosen date)</span></label>
            <select id="appt-pd-window" class="appt-input">
              <option value="">No pickup — customer brings it</option>
              @foreach($pdModalWindows as $w)
                <option value="{{ $w->id }}" data-days="{{ implode(',', $w->days ?? []) }}">{{ $w->label }} · {{ $w->max_stops }} stops/day</option>
              @endforeach
            </select>
          </div>
          <div>
            <label style="display:block;font-size:11px;color:var(--ia-text-muted);margin-bottom:4px">Need by</label>
            <input type="date" id="appt-pd-needby" class="appt-input">
          </div>
        </div>
        <script>
        (function () {
          // MARKER-PATCH-519 — grey window options that don't run on the picked date
          window.apptPdFilter = function (dateStr) {
            var sel = document.getElementById('appt-pd-window');
            if (!sel || !dateStr) return;
            var d = new Date(dateStr + 'T12:00:00');
            var iso = d.getDay() === 0 ? 7 : d.getDay();
            Array.prototype.forEach.call(sel.options, function (o) {
              if (!o.value) return;
              var days = (o.dataset.days || '').split(',');
              var ok = days.indexOf(String(iso)) !== -1;
              o.disabled = !ok;
              if (!ok && o.selected) sel.value = '';
            });
            var nb = document.getElementById('appt-pd-needby');
            if (nb) nb.min = dateStr;
          };
        })();
        </script>
        @endif
      </div>

      <div class="appt-foot">
        <button type="button" class="appt-btn appt-btn--cancel" onclick="ApptModal.close()">Cancel</button>
        {{-- MARKER-NOTIFY-MODAL — "Save" says what the person is doing; the
             old label described what the software did and gave no hint that a
             message might follow. --}}
        <button type="button" class="appt-btn appt-btn--create" id="appt-submit" onclick="ApptModal.submit()">Save appointment</button>
      </div>
    </div>
  </div>
</div>

<script>
window.ApptModal = (function () {
  // SEQUENTIAL-PICKER-STATE v1
  var state = {
    services: [],
    resources: [],          // all active (loaded once for caching, but not used for picker)
    eligibleResources: [],  // narrowed by selected service
    cart: [],               // single-element at launch (one service); prepped for future multi
    customerId: null,
    pickerOpen: false,
    selectedSlot: null,     // {date, time, resource_id}
    selectedServiceId: null,
    selectedResourceId: null,
    selectedResourceName: '',
    weekStartDate: null,    // YYYY-MM-DD; advances on next/prev week
    availSlots: [],
    availLoading: false,
  };

  var routes = {
    pickerData: "{{ route('tenant.appointments.picker-data') }}",
    store:      "{{ route('tenant.appointments.store') }}",
    eligibleResources: "{{ route('tenant.appointments.eligible-resources') }}",
    weekTimes:         "{{ route('tenant.appointments.week-times') }}",
  };

  // MARKER-APPT-ASSET — asset picker config (label + endpoint from the tenant)
  var assetsCfg = {
    enabled: {{ $currentTenant->multi_asset_enabled ? 'true' : 'false' }},
    singular: @json(strtolower($currentTenant->asset_label_singular ?: 'item')),
    url: "{{ route('tenant.appointments.customer-assets') }}",
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
        populateServices();
      })
      .catch(function () { showError('Could not load services. Try again.'); });
  }

  // SERVICE-TYPEAHEAD v1 — filterable list over state.services.
  function svcMeta(svc) {
    // SERVICE-LABEL-DEDUPE v1: skip "(N min)" suffix if the name already has one.
    var nameHasDuration = /\(\s*\d+\s*min\s*\)/i.test(svc.name);
    var dur = (svc.duration_minutes && !nameHasDuration) ? svc.duration_minutes + ' min' : '';
    var price = (svc.price_cents != null) ? fmt(svc.price_cents) : '';
    return [dur, price].filter(Boolean).join(' · ');
  }
  var svcHighlight = -1;
  function svcFiltered() {
    var q = (el('appt-sp-service-search').value || '').toLowerCase().trim();
    var list = state.services.slice().sort(function (a, b) { return a.name.localeCompare(b.name); });
    if (!q) return list;
    return list.filter(function (svc) { return svc.name.toLowerCase().indexOf(q) !== -1; });
  }
  function renderServiceResults() {
    var box = el('appt-sp-service-results');
    var list = svcFiltered();
    if (!list.length) {
      box.innerHTML = '<div class="appt-cust-row" style="cursor:default;opacity:.55">No matching services.</div>';
      box.style.display = 'block';
      return;
    }
    box.innerHTML = list.map(function (svc, i) {
      return '<div class="appt-cust-row' + (i === svcHighlight ? '" style="background:rgba(255,255,255,.06)"' : '"')
        + ' data-svc-id="' + svc.id + '">'
        + '<div>' + escapeHtml(svc.name) + '</div>'
        + '<div class="meta">' + escapeHtml(svcMeta(svc)) + '</div></div>';
    }).join('');
    box.style.display = 'block';
    var hi = box.children[svcHighlight];
    if (hi && hi.scrollIntoView) hi.scrollIntoView({ block: 'nearest' });
  }
  function hideServiceResults() {
    svcHighlight = -1;
    var box = el('appt-sp-service-results');
    if (box) box.style.display = 'none';
  }
  function selectService(id) {
    var svc = null;
    state.services.forEach(function (x) { if (String(x.id) === String(id)) svc = x; });
    if (!svc) return;
    el('appt-sp-service-selected-name').textContent = svc.name;
    el('appt-sp-service-selected-meta').textContent = svcMeta(svc);
    el('appt-sp-service-selected').style.display = 'flex';
    el('appt-sp-service-wrap').style.display = 'none';
    hideServiceResults();
    applyServiceSelection(String(svc.id));
  }
  function clearService() {
    el('appt-sp-service-selected').style.display = 'none';
    el('appt-sp-service-wrap').style.display = 'block';
    var inp = el('appt-sp-service-search');
    inp.value = '';
    applyServiceSelection('');
    inp.focus();
  }
  function populateServices() {
    // Typeahead renders on demand; nothing to pre-populate.
  }

  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.selectedSlot = null;
    state.selectedServiceId = null;
    state.selectedResourceId = null;
    state.selectedResourceName = '';
    state.weekStartDate = todayStr();
    state.availSlots = [];
    state.availLoading = false;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-new-fields').style.display = 'none';
    ['appt-first','appt-last','appt-email','appt-phone','appt-notes'].forEach(function (id) { el(id).value = ''; });
    // Reset sequential picker UI
    el('appt-sp-service-search').value = '';
    el('appt-sp-service-selected').style.display = 'none';
    el('appt-sp-service-wrap').style.display = 'block';
    hideServiceResults();
    el('appt-sp-resource').innerHTML = '<option value="">Select a resource…</option>';
    el('appt-sp-resource-section').style.display = 'none';
    el('appt-sp-find-section').style.display = 'none';
    el('appt-sp-times-section').style.display = 'none';
    el('appt-sp-times-list').innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    populateServices();
    el('appt-sp-service-search').focus();
  }

  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
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
      assetNewOnly();
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
      row.innerHTML = '<div>' + escapeHtml(c.name || (c.first_name + ' ' + c.last_name)) + '</div>' // MARKER-BIZ-NAME
        + '<div class="meta">' + escapeHtml(c.email || c.phone || '') + '</div>';
      row.addEventListener('click', function () { attachCustomer(c); });
      box.appendChild(row);
    });
    box.style.display = 'block';
    el('appt-cust-new-fields').style.display = 'none';
  }

  function attachCustomer(c) {
    state.customerId = c.id;
    el('appt-cust-attached-name').textContent = (c.name || (c.first_name + ' ' + c.last_name)).trim(); // MARKER-BIZ-NAME
    el('appt-cust-attached-meta').textContent = c.email || c.phone || '';
    el('appt-cust-attached').style.display = 'flex';
    el('appt-cust-search-wrap').style.display = 'none';
    assetLoadFor(c.id);
  }

  function clearCustomer() {
    state.customerId = null;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-search').focus();
    assetReset();
  }

  // ── MARKER-APPT-ASSET — single asset select/create ──
  function assetOpt(v, label){ var o=document.createElement('option'); o.value=v; o.textContent=label; return o; }
  function assetReset(){
    if(!assetsCfg.enabled) return;
    var need=el('appt-asset-need-customer'); if(need) need.style.display='block';
    var sel=el('appt-asset-select'); if(sel){ sel.innerHTML=''; sel.style.display='none'; }
    var nw=el('appt-asset-new'); if(nw) nw.style.display='none';
    var nm=el('appt-asset-name'); if(nm) nm.value='';
    var ni=el('appt-asset-id'); if(ni) ni.value='';
  }
  function assetNewOnly(){
    if(!assetsCfg.enabled) return;
    var need=el('appt-asset-need-customer'); if(need) need.style.display='none';
    var sel=el('appt-asset-select'); if(sel) sel.style.display='none';
    var nw=el('appt-asset-new'); if(nw) nw.style.display='block';
  }
  function assetLoadFor(customerId){
    if(!assetsCfg.enabled) return;
    assetReset();
    var need=el('appt-asset-need-customer'); if(need) need.style.display='none';
    fetch(assetsCfg.url + '?customer_id=' + encodeURIComponent(customerId), { headers:{'Accept':'application/json'}, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        var assets=(d && d.assets) || [];
        var sel=el('appt-asset-select');
        if(assets.length && sel){
          sel.innerHTML='';
          sel.appendChild(assetOpt('', 'Select ' + assetsCfg.singular + '\u2026'));
          assets.forEach(function(a){ sel.appendChild(assetOpt(a.id, a.name + (a.identifier ? ' \u00b7 ' + a.identifier : ''))); });
          sel.appendChild(assetOpt('__new__', '+ Add new ' + assetsCfg.singular));
          sel.style.display='block';
          var nw=el('appt-asset-new'); if(nw) nw.style.display='none';
        } else {
          assetNewOnly();
        }
      })
      .catch(function(){ assetNewOnly(); });
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
    // SEQUENTIAL-PICKER-DEAD-PATH: cart helpers retained for compat, no-op in new flow.
    state.cart.push({ service_item_id: s.id, name: s.name, duration: s.duration_minutes, price: s.price_cents, override: null });
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

  // SEQUENTIAL-PICKER-HANDLERS v1
  // Service change → load eligible resources, reset downstream UI.
  function applyServiceSelection(serviceId) {
    state.selectedServiceId = serviceId || null;
    state.selectedResourceId = null;
    state.selectedResourceName = '';
    state.selectedSlot = null;
    el('appt-sp-resource').innerHTML = '<option value="">Loading resources…</option>';
    el('appt-sp-find-section').style.display = 'none';
    el('appt-sp-times-section').style.display = 'none';
    if (!serviceId) {
      el('appt-sp-resource-section').style.display = 'none';
      return;
    }
    el('appt-sp-resource-section').style.display = 'block';
    fetch(routes.eligibleResources + '?service_id=' + encodeURIComponent(serviceId), {
      headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var resources = data.resources || [];
        state.eligibleResources = resources;
        var rsel = el('appt-sp-resource');
        rsel.innerHTML = '<option value="">Select a resource…</option>';
        if (resources.length === 0) {
          rsel.innerHTML = '<option value="">No eligible resources for this service</option>';
          return;
        }
        resources.forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.id;
          opt.textContent = r.name + (r.subtitle ? ' · ' + r.subtitle : '');
          rsel.appendChild(opt);
        });
      })
      .catch(function () { showError('Could not load resources.'); });
  }

  function onResourceChange() {
    var sel = el('appt-sp-resource');
    var resourceId = sel.value;
    state.selectedResourceId = resourceId || null;
    state.selectedResourceName = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
    state.selectedSlot = null;
    el('appt-sp-times-section').style.display = 'none';
    if (resourceId) {
      el('appt-sp-find-section').style.display = 'block';
    } else {
      el('appt-sp-find-section').style.display = 'none';
    }
  }

  function onFindTimes() {
    if (!state.selectedServiceId || !state.selectedResourceId) return;
    state.weekStartDate = state.weekStartDate || todayStr();
    fetchWeekTimes();
  }

  function fetchWeekTimes() {
    var listEl = el('appt-sp-times-list');
    listEl.innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('appt-sp-times-section').style.display = 'block';
    state.availLoading = true;
    el('appt-sp-week-label').textContent = formatWeekLabel(state.weekStartDate);
    el('appt-sp-prev-week').disabled = (state.weekStartDate <= todayStr());

    var url = routes.weekTimes
      + '?service_id='  + encodeURIComponent(state.selectedServiceId)
      + '&resource_id=' + encodeURIComponent(state.selectedResourceId)
      + '&start_date='  + encodeURIComponent(state.weekStartDate);
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.availLoading = false;
        state.availSlots = data.slots || [];
        renderTimes();
      })
      .catch(function () {
        state.availLoading = false;
        listEl.innerHTML = '<div class="appt-sp-times-empty error">Could not load available times.</div>';
      });
  }

  function renderTimes() {
    var listEl = el('appt-sp-times-list');
    if (!state.availSlots || state.availSlots.length === 0) {
      listEl.innerHTML = '<div class="appt-sp-times-empty">No available times this week. Try Next week →</div>';
      return;
    }
    var html = '';
    state.availSlots.forEach(function (slot, idx) {
      var isSel = state.selectedSlot
        && state.selectedSlot.date === slot.date
        && state.selectedSlot.time === slot.time;
      html += '<div class="appt-sp-time-row' + (isSel ? ' selected' : '') + '" data-idx="' + idx + '">'
        + '<span class="appt-sp-time-date">' + escapeHtml(slot.date_label) + '</span>'
        + '<span class="appt-sp-time-time">' + escapeHtml(slot.time_label) + '</span>'
        + '</div>';
    });
    listEl.innerHTML = html;
    listEl.querySelectorAll('.appt-sp-time-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var idx = parseInt(row.getAttribute('data-idx'), 10);
        var slot = state.availSlots[idx];
        state.selectedSlot = {
          date: slot.date,
          time: slot.time,
          resource_id: state.selectedResourceId,
        };
        if (window.apptPdFilter) window.apptPdFilter(slot.date); // MARKER-PATCH-519
        renderTimes();
      });
    });
  }

  function onPrevWeek() {
    if (!state.weekStartDate) return;
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() - 7);
    var ymd = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (ymd < todayStr()) ymd = todayStr();
    state.weekStartDate = ymd;
    fetchWeekTimes();
  }

  function onNextWeek() {
    if (!state.weekStartDate) state.weekStartDate = todayStr();
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() + 7);
    state.weekStartDate = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    fetchWeekTimes();
  }

  function formatWeekLabel(startDate) {
    if (!startDate) return '—';
    var s = new Date(startDate + 'T00:00:00');
    var e = new Date(s);
    e.setDate(e.getDate() + 6);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[s.getMonth()] + ' ' + s.getDate() + ' – ' + months[e.getMonth()] + ' ' + e.getDate();
  }

  // Wire up sequential picker events (idempotent — only once).
  (function wireSequentialPicker() {
    var svcInp = el('appt-sp-service-search');
    if (!svcInp || svcInp.dataset.spWired) return;
    svcInp.dataset.spWired = '1';
    svcInp.addEventListener('input', function () { svcHighlight = -1; renderServiceResults(); });
    svcInp.addEventListener('focus', renderServiceResults);
    svcInp.addEventListener('keydown', function (e) {
      var list = svcFiltered();
      if (e.key === 'ArrowDown') { e.preventDefault(); svcHighlight = Math.min(svcHighlight + 1, list.length - 1); renderServiceResults(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); svcHighlight = Math.max(svcHighlight - 1, 0); renderServiceResults(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (list[svcHighlight]) selectService(list[svcHighlight].id); else if (list.length === 1) selectService(list[0].id); }
      else if (e.key === 'Escape') { hideServiceResults(); }
    });
    el('appt-sp-service-results').addEventListener('click', function (e) {
      var row = e.target.closest('[data-svc-id]');
      if (row) selectService(row.getAttribute('data-svc-id'));
    });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('#appt-sp-service-wrap')) hideServiceResults();
    });
    el('appt-sp-resource').addEventListener('change', onResourceChange);
    el('appt-sp-find').addEventListener('click', onFindTimes);
    el('appt-sp-prev-week').addEventListener('click', onPrevWeek);
    el('appt-sp-next-week').addEventListener('click', onNextWeek);
  })();

  // ── Submit ──
  // MARKER-NOTIFY-MODAL-REMOVED — askNotify() lived here and is gone.
  // Creating an appointment redirects to the appointment page; the
  // Send confirmation action belongs there, not in a stacked overlay.


  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
    });
  }

  function submit() {
    clearError();
    if (!state.selectedServiceId) return showError('Pick a service.');
    if (!state.selectedResourceId) return showError('Pick a resource.');
    if (!state.selectedSlot || !state.selectedSlot.date) return showError('Pick a time.');

    var btn = el('appt-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="appt-spin"></span>Creating…';

    var payload = {
      customer_id: state.customerId,
      appointment_date: state.selectedSlot.date,
      appointment_time: state.selectedSlot.time,
      resource_id: state.selectedResourceId,
      staff_notes: el('appt-notes').value || null,
      route_window_id: (el('appt-pd-window') && el('appt-pd-window').value) || null, // MARKER-PATCH-519
      need_by: (el('appt-pd-needby') && el('appt-pd-needby').value) || null,
      items: [
        { service_item_id: state.selectedServiceId, price_override_cents: null },
      ],
    };
    if (!state.customerId) {
      payload.customer_first_name = el('appt-first').value.trim();
      payload.customer_last_name  = el('appt-last').value.trim();
      payload.customer_email      = el('appt-email').value.trim();
      payload.customer_phone      = el('appt-phone').value.trim();
      if (!payload.customer_first_name || !payload.customer_last_name || !payload.customer_email) {
        showError('First name, last name, and email are required for a new customer.');
        btn.disabled = false; btn.innerHTML = 'Save appointment';
        return;
      }
    }

    if (assetsCfg.enabled) {
      var _an = el('appt-asset-name'), _ai = el('appt-asset-id'), _as = el('appt-asset-select');
      var _nw = el('appt-asset-new');
      var _newVisible = _nw && _nw.style.display !== 'none';
      var _name = _an ? _an.value.trim() : '';
      var _selVal = _as ? _as.value : '';
      if (_newVisible && _name) {
        payload.assets = [{ client_key: 'a1', name_snapshot: _name, identifier: (_ai && _ai.value.trim()) || null }];
        payload.items[0].asset_client_key = 'a1';
      } else if (_selVal && _selVal !== '__new__') {
        payload.assets = [{ client_key: 'a1', customer_asset_id: _selVal }];
        payload.items[0].asset_client_key = 'a1';
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
        // MARKER-NOTIFY-MODAL-REMOVED — saved, and nobody has been told.
        // Straight to the appointment; notifying is a deliberate action
        // there, never a side effect of creating the record.
        if (res.body.redirect) { window.location.href = res.body.redirect; }
        else { window.location.reload(); }
        return;
      }
      // If the slot got taken between fetch and submit, refresh week-times.
      if (res.body && res.body.code === 'lock_timeout') {
        showError('That slot was just taken. Recomputing…');
        if (state.selectedServiceId && state.selectedResourceId) fetchWeekTimes();
        btn.disabled = false; btn.innerHTML = 'Save appointment';
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
    open: open, close: close, clearCustomer: clearCustomer, clearService: clearService,
    toggleServicePicker: toggleServicePicker, submit: submit,
  };
})();

window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };

// BFCACHE-MODAL-RESET v1
// When the user navigates back to a page where this modal lives, the browser
// may bfcache-restore the page mid-submit (frozen spinner, modal still open).
// Detect persisted-restore and reset modal + submit button state.
window.addEventListener('pageshow', function (e) {
  if (!e.persisted) return;
  var modal = document.getElementById('new-appt-modal');
  if (modal) modal.style.display = 'none';
  var btn = document.getElementById('appt-submit');
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = 'Create Appointment';
  }
});
</script>
