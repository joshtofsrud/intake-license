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
    .appt-sp-refresh-state { font-size:11px; color:var(--ia-text-dim); margin-left:8px; }
    .appt-sp-refresh-state a { color:var(--ia-text-muted); text-decoration:underline; }
    .appt-day-changed { margin-top:10px; font-size:12px; color:#f0c46a; border-left:2px solid rgba(240,196,106,.5); padding-left:9px; }

    /* a short list of choices, shown rather than hidden */
    .appt-tiles-grid { display:grid; gap:7px; }
    .appt-tile { text-align:left; background:var(--ia-surface-2); border:0.5px solid var(--ia-border); border-radius:8px; padding:10px 12px; cursor:pointer; font-family:inherit; color:var(--ia-text); transition:all .12s ease; display:flex; flex-direction:column; gap:2px; }
    .appt-tile:hover { border-color:var(--ia-border-strong); }
    .appt-tile.on { border-color:var(--ia-accent); background:var(--ia-accent-soft); }
    .appt-tile.add { border-style:dashed; color:var(--ia-text-muted); }
    .appt-tile-l { font-size:13.5px; font-weight:600; }
    .appt-tile-s { font-size:11.5px; color:var(--ia-text-dim); }
    .appt-tile.on .appt-tile-s { color:var(--ia-text-muted); }
    .appt-tiles-filter { font-size:12.5px; padding:7px 10px; }

    /* day cards for capacity mode */
    .appt-day-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(116px,1fr)); gap:8px; }
    .appt-day { position:relative; border:0.5px solid var(--ia-border); border-radius:8px; padding:10px 11px; cursor:pointer; background:var(--ia-surface-2); transition:all .12s ease; }
    .appt-day:hover { border-color:var(--ia-border-strong); }
    .appt-day.sel { border-color:var(--ia-accent); background:var(--ia-accent-soft); }
    .appt-day.notice { border-style:dashed; border-color:rgba(240,196,106,.4); }
    .appt-day.notice .appt-day-c { color:#f0c46a; }
    .appt-day.over { border-color:rgba(248,113,113,.4); }
    .appt-day.over .appt-day-c { color:#f87171; }
    .appt-day.over .appt-day-bar i { background:#f87171; }
    .appt-day.shut { opacity:.42; cursor:not-allowed; }
    .appt-day-d { font-size:13px; font-weight:600; }
    .appt-day-c { font-size:11.5px; color:var(--ia-text-dim); margin-top:2px; }
    .appt-day-bar { height:4px; border-radius:99px; background:rgba(255,255,255,.1); margin-top:7px; overflow:hidden; }
    .appt-day-bar i { display:block; height:100%; background:var(--ia-accent); }
    .appt-day-flag { position:absolute; top:6px; right:8px; font-size:9px; letter-spacing:.05em; text-transform:uppercase; color:var(--ia-text-dim); }
    .appt-day-note { font-size:11.5px; color:var(--ia-text-dim); margin-top:10px; }
    .appt-day-warn { margin-top:12px; border-radius:8px; padding:10px 12px; font-size:12.5px; line-height:1.5; background:rgba(240,196,106,.1); border:0.5px solid rgba(240,196,106,.32); color:#f0c46a; }
    .appt-day-warn.bad { background:rgba(248,113,113,.1); border-color:rgba(248,113,113,.32); color:#f87171; }
    .appt-day-sub { margin-top:7px; }
    .appt-day-sw { display:flex; align-items:center; gap:8px; color:var(--ia-text); font-size:12.5px; margin-bottom:5px; cursor:pointer; }
    .appt-day-sw input { accent-color:var(--ia-accent); }
    .appt-day-warn .appt-input { margin-top:7px; font-size:12.5px; }

    .appt-sp-times-list {
      max-height: 240px;       /* ~5 rows visible */
      overflow-y: auto;
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: 8px;
      background: rgba(255,255,255,.02);
    }
    /* the day grid brings its own cards, so the
       panel that frames a list of times is a box around boxes. It also has to
       lose the 240px scroll, which clips a two-row grid for no reason. */
    .appt-sp-times-list.is-days {
      max-height: none;
      overflow: visible;
      border: 0;
      border-radius: 0;
      background: none;
    }
  .appt-sp-short{margin-left:8px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#f0c46a;border:0.5px solid rgba(240,196,106,.35);border-radius:99px;padding:1px 7px}
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
  
    /* MARKER-MULTI-ASSET-MODAL — the Items list */
    #new-appt-card select.appt-input { -webkit-appearance: none; appearance: none; padding-right: 36px; text-overflow: ellipsis; white-space: nowrap; overflow: hidden;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23a3a3a3' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 14px center; background-size: 12px 12px; }
    .wi-list { display: flex; flex-direction: column; gap: 8px; }
    .wi-row { display: grid; grid-template-columns: 22px minmax(0, 1fr) minmax(0, 1.25fr) 28px; gap: 8px; align-items: start; padding: 10px; border: 1px solid var(--ia-border, rgba(255,255,255,.12)); border-radius: 10px; background: var(--ia-surface-2, rgba(255,255,255,.03)); }
    .wi-n { font: 600 11px var(--ia-font-mono, monospace); opacity: .5; padding-top: 27px; text-align: center; }
    .wi-row select.appt-input { width: 100%; }
    .wi-lbl { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; opacity: .5; margin: 0 0 4px 2px; }
    .wi-new { display: grid; grid-template-columns: 1fr; gap: 6px; margin-top: 6px; }
    .wi-svcline { display: flex; gap: 6px; align-items: center; }
    .wi-svcline + .wi-svcline { margin-top: 6px; }
    .wi-svcline select { flex: 1; min-width: 0; }
    .wi-ta { position: relative; flex: 1; min-width: 0; }
    .wi-ta-in { width: 100%; text-overflow: ellipsis; }
    .wi-ta-res { position: absolute; left: 0; right: 0; top: 100%; z-index: 30; max-height: 260px; box-shadow: 0 12px 28px rgba(0,0,0,.35); }
    .wi-ta-res .appt-cust-row.on { background: rgba(255,255,255,.08); }
    .wi-x { background: none; border: 0; color: var(--ia-text-dim, rgba(255,255,255,.5)); font-size: 18px; line-height: 1; cursor: pointer; padding: 25px 0 0; border-radius: 6px; }
    .wi-x:hover { color: var(--ia-text, #fff); }
    .wi-x[disabled] { visibility: hidden; }
    .wi-x-sm, .wi-x-pad { flex: none; width: 22px; text-align: center; font-size: 15px; padding: 6px 0; }
    .wi-more { background: none; border: 0; color: var(--ia-text-muted, rgba(255,255,255,.7)); font: inherit; font-size: 12px; cursor: pointer; padding: 6px 2px 0; }
    .wi-more:hover { color: var(--ia-text, #fff); text-decoration: underline; }
    .wi-add { margin-top: 8px; width: 100%; text-align: left; background: none; border: 1px dashed var(--ia-border-strong, rgba(255,255,255,.22)); color: var(--ia-text, inherit); border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 13px; cursor: pointer; }
    .wi-add:hover { border-style: solid; }
    .wi-sum { display: flex; justify-content: space-between; margin-top: 10px; font-size: 12.5px; opacity: .75; }
    .wi-sum b { font-weight: 600; }
    @media (max-width: 640px) {
      .wi-row { grid-template-columns: 22px minmax(0, 1fr) 28px; }
      .wi-row .wi-svc { grid-column: 2 / 3; }
      .wi-row > .wi-x { grid-column: 3; grid-row: 1; }
    }
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
              {{-- optional address at creation, so the
                   record doesn't start life failing data health. --}}
              <div style="margin-top:8px">
                <input type="text" id="appt-addr" class="appt-input" placeholder="Street address" autocomplete="off">
              </div>
              <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;margin-top:8px">
                <input type="text" id="appt-city"  class="appt-input" placeholder="City"  autocomplete="off">
                <input type="text" id="appt-state" class="appt-input" placeholder="State" autocomplete="off">
                <input type="text" id="appt-post"  class="appt-input" placeholder="ZIP"   autocomplete="off" inputmode="numeric">
              </div>
              <div style="font-size:11px;opacity:.55;margin-top:6px">No match — a new customer will be created. Address is optional.</div>
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

        {{-- MARKER-MULTI-ASSET-MODAL: one Items list. Every item is the same row:
             which item (the customer's saved ones, or a new one named in the row),
             its services, and remove. One "+ Add item". The total under the list
             is what the day and time search uses. Words come from the shop's own
             name for items. Shops that don't track items keep the Service section. --}}
        @if($currentTenant->multi_asset_enabled)
        @php $aSing = strtolower($currentTenant->asset_label_singular ?: 'item'); $aPlur = strtolower($currentTenant->asset_label_plural ?: ($aSing.'s')); @endphp
        <div class="appt-section" id="appt-asset-section">
          <div class="appt-section-h">{{ ucfirst($aPlur) }}</div>
          <div class="wi-list" id="appt-items"></div>
          <button type="button" class="wi-add" id="appt-items-add" onclick="ApptModal.addItem()">+ Add {{ $aSing }}</button>
          <div class="wi-sum" id="appt-items-sum"></div>
        </div>
        @endif

        {{-- SEQUENTIAL-PICKER v1 --}}
        {{-- MARKER-MULTI-ASSET-MODAL: shops that track items pick services on each item above --}}
        <div class="appt-section"@if($currentTenant->multi_asset_enabled) style="display:none"@endif>
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
          <div id="appt-sp-resource-tiles" class="appt-tiles" style="display:none"></div>
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
            <div class="appt-section-h" id="appt-sp-times-head-label" style="margin-bottom:0">Available times</div>
            <div class="appt-sp-week-nav">
              <button type="button" class="appt-sp-week-btn" id="appt-sp-prev-week" disabled>← Prev week</button>
              <span class="appt-sp-week-label" id="appt-sp-week-label">—</span>
              <button type="button" class="appt-sp-week-btn" id="appt-sp-next-week">Next week →</button>
              <span class="appt-sp-refresh-state" id="appt-sp-refresh-state"></span>
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

        {{-- pickup window + need-by (route tenants only) --}}
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
          // grey window options that don't run on the picked date
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
        {{-- "Save" says what the person is doing; the
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
    dayLoad: [],            // [{date,state,left,max,…}]
    selectedDay: null,      // {date,state,left,max}
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
    dayLoad:           "{{ route('tenant.appointments.day_load') }}",
  };

  // a drop_off shop takes a number of jobs a day, so the
  // question is which DAY, not which minute. The mode decides which picker
  // runs; everything else in this modal is the same either way.
  var bookingMode = @json($currentTenant->booking_mode ?? 'drop_off');
  var dayPolicy = { short_notice: false, warns: true, overbook: false, notice_hours: 0 };

  var autoRefresh = @json((bool) ($currentTenant->availability_auto_refresh ?? true));
  var settingsUrl = @json(route('tenant.settings.index'));
  var refreshTimer = null;

  // asset picker config (label + endpoint from the tenant)
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
        if (assetsCfg.enabled) renderItems(); // MARKER-MULTI-ASSET-MODAL
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
    var rt = el('appt-sp-resource-tiles'); if (rt) { rt.innerHTML = ''; rt.style.display = 'none'; }
    assetReset(); // MARKER-MULTI-ASSET-MODAL: one empty item to start
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

  function close() {
    stopAutoRefresh(); // no timer outlives its screen.
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
      row.innerHTML = '<div>' + escapeHtml(c.name || (c.first_name + ' ' + c.last_name)) + '</div>'
        + '<div class="meta">' + escapeHtml(c.email || c.phone || '') + '</div>';
      row.addEventListener('click', function () { attachCustomer(c); });
      box.appendChild(row);
    });
    box.style.display = 'block';
    el('appt-cust-new-fields').style.display = 'none';
  }

  function attachCustomer(c) {
    state.customerId = c.id;
    el('appt-cust-attached-name').textContent = (c.name || (c.first_name + ' ' + c.last_name)).trim();
    el('appt-cust-attached-meta').textContent = c.email || c.phone || '';
    el('appt-cust-attached').style.display = 'flex';
    el('appt-cust-search-wrap').style.display = 'none';
    assetLoadFor(c.id);
  }

  function clearCustomer() {
    ['appt-addr', 'appt-city', 'appt-state', 'appt-post'].forEach(function (id) { var n = el(id); if (n) n.value = ''; });
    state.customerId = null;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-search').focus();
    assetReset();
  }

  // ── Items (MARKER-MULTI-ASSET-MODAL) ──
  // rows: { key, item: '' | saved asset id | '__new__', name, ident, svcs: [service ids] }
  var itemSeq = 0;
  state.rows = [];
  state.custAssets = [];
  var plural = @json(strtolower($currentTenant->asset_label_plural ?: (($currentTenant->asset_label_singular ?: 'item') . 's')));
  function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
  function newRow(svc) { return { key: 'a' + (++itemSeq), item: state.custAssets.length ? '' : '__new__', name: '', ident: '', svcs: [svc || ''] }; }
  function svcById(id) { var f = null; state.services.forEach(function (x) { if (String(x.id) === String(id)) f = x; }); return f; }
  function svcLabel(x) { var dur = x.duration_minutes && !/\(\s*\d+\s*min\s*\)/i.test(x.name) ? ' · ' + x.duration_minutes + ' min' : ''; return x.name + dur; }
  function assetReset() {
    if (!assetsCfg.enabled) return;
    state.custAssets = [];
    state.rows = [newRow('')];
    renderItems();
  }
  function assetNewOnly() {
    if (!assetsCfg.enabled) return;
    state.rows.forEach(function (r) { if (!r.item) r.item = '__new__'; });
    renderItems();
  }
  function assetLoadFor(customerId) {
    if (!assetsCfg.enabled) return;
    state.custAssets = [];
    fetch(assetsCfg.url + '?customer_id=' + encodeURIComponent(customerId), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        state.custAssets = (d && d.assets) || [];
        // one saved item: picked for them; several: they choose; none: name a new one
        state.rows.forEach(function (r, i) {
          if (r.item === '' || r.item === '__new__' && !r.name) r.item = state.custAssets.length === 1 && i === 0 ? String(state.custAssets[0].id) : (state.custAssets.length ? '' : '__new__');
        });
        renderItems();
      })
      .catch(function () { assetNewOnly(); });
  }
  function renderItems() {
    var box = el('appt-items'); if (!box) return;
    var sing = assetsCfg.singular;
    box.innerHTML = state.rows.map(function (r, i) {
      var taken = {};
      state.rows.forEach(function (o, j) { if (j !== i && o.item && o.item !== '__new__') taken[o.item] = 1; });
      var io = '<option value="">Pick ' + (/^[aeiou]/i.test(sing) ? 'an ' : 'a ') + esc(sing) + '…</option>' + state.custAssets.map(function (a) {
        var t = taken[String(a.id)];
        return '<option value="' + esc(a.id) + '"' + (String(a.id) === r.item ? ' selected' : '') + (t ? ' disabled' : '') + '>' + esc(a.name + (a.identifier ? ' · ' + a.identifier : '')) + (t ? ' (already added)' : '') + '</option>';
      }).join('') + '<option value="__new__"' + (r.item === '__new__' ? ' selected' : '') + '>+ New ' + esc(sing) + '…</option>';
      var mins = r.svcs.reduce(function (a, id) { var x = svcById(id); return a + (x ? (x.duration_minutes || 0) : 0); }, 0);
      var lines = r.svcs.map(function (id, k) {
        var cur = svcById(id);
        return '<div class="wi-svcline"><div class="wi-ta"><input type="text" class="appt-input wi-ta-in" data-k="' + k + '" value="' + esc(cur ? svcLabel(cur) : '') + '" placeholder="Search services…" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-label="Service"></div>'
          + (k > 0 ? '<button type="button" class="wi-x wi-x-sm" data-f="rmsvc" data-k="' + k + '" aria-label="Remove this service">&times;</button>' : '<span class="wi-x-pad"></span>') + '</div>';
      }).join('');
      return '<div class="wi-row" data-i="' + i + '"><div class="wi-n">' + (i + 1) + '</div>'
        + '<div><div class="wi-lbl">' + esc(cap(sing)) + '</div><select class="appt-input" data-f="item" aria-label="' + esc(cap(sing)) + ' ' + (i + 1) + '">' + io + '</select>'
        + (r.item === '__new__' ? '<div class="wi-new"><input type="text" class="appt-input" data-f="name" placeholder="Make &amp; model" value="' + esc(r.name) + '"><input type="text" class="appt-input" data-f="ident" placeholder="Serial (optional)" value="' + esc(r.ident) + '"></div>' : '')
        + '</div>'
        + '<div class="wi-svc"><div class="wi-lbl">' + (r.svcs.length > 1 ? 'Services · ' + mins + ' min' : 'Service') + '</div>' + lines
        + '<button type="button" class="wi-more" data-f="addsvc">+ service</button></div>'
        + '<button type="button" class="wi-x" data-f="rm" aria-label="Remove ' + esc(sing) + ' ' + (i + 1) + '"' + (state.rows.length === 1 ? ' disabled' : '') + '>&times;</button>'
        + '</div>';
    }).join('');
    var all = [].concat.apply([], state.rows.map(function (r) { return r.svcs.filter(Boolean); }));
    var mins = 0, cents = 0;
    all.forEach(function (id) { var x = svcById(id); if (x) { mins += x.duration_minutes || 0; cents += x.price_cents || 0; } });
    var sum = el('appt-items-sum');
    if (sum) sum.innerHTML = '<span><b>' + state.rows.length + ' ' + esc(state.rows.length === 1 ? sing : plural) + '</b> · ' + all.length + ' ' + (all.length === 1 ? 'service' : 'services') + (mins ? ' · ' + mins + ' min total' : '') + '</span><span>' + (cents ? fmt(cents) : '') + '</span>';
  }
  // the first item's first service picks who can do it; the rest only lengthen the time searched
  function itemsChanged(firstChanged) {
    var first = (state.rows[0] && state.rows[0].svcs[0]) || '';
    if (firstChanged || String(first) !== String(state.selectedServiceId || '')) { applyServiceSelection(first ? String(first) : ''); return; }
    state.selectedSlot = null;
    if (state.selectedServiceId && state.selectedResourceId) onFindTimes();
  }
  function extraServiceIds() {
    var out = [];
    state.rows.forEach(function (r, i) { r.svcs.forEach(function (id, k) { if (id && !(i === 0 && k === 0)) out.push(id); }); });
    return out;
  }
  function addItem() {
    var first = (state.rows[0] && state.rows[0].svcs[0]) || '';
    state.rows.push(newRow(first));
    renderItems(); itemsChanged(false);
    var s = document.querySelector('#appt-items .wi-row:last-child [data-f="item"]'); if (s) s.focus();
  }
  (function () {
    var box = el('appt-items'); if (!box) return;
    box.addEventListener('change', function (e) {
      var row = e.target.closest('.wi-row'); if (!row) return;
      var i = +row.dataset.i, r = state.rows[i], f = e.target.dataset.f;
      if (f === 'item') {
        r.item = e.target.value; renderItems();
        if (r.item === '__new__') { var n = box.querySelector('.wi-row[data-i="' + i + '"] [data-f="name"]'); if (n) n.focus(); }
      }
      if (f === 'svc') { var k = +e.target.dataset.k; r.svcs[k] = e.target.value; renderItems(); itemsChanged(i === 0 && k === 0); }
    });
    // service search: focus lists every service, typing narrows it, arrows and Enter pick
    var ta = null; // { input, i, k, hi }
    function taList(q) {
      q = (q || '').trim().toLowerCase();
      var list = state.services.slice().sort(function (a, b) { return a.name.localeCompare(b.name); });
      return q ? list.filter(function (x) { return x.name.toLowerCase().indexOf(q) > -1; }) : list;
    }
    function taRender() {
      if (!ta) return;
      var wrap = ta.input.parentNode, res = wrap.querySelector('.wi-ta-res');
      if (!res) { res = document.createElement('div'); res.className = 'appt-cust-results wi-ta-res'; res.setAttribute('role', 'listbox'); wrap.appendChild(res); }
      var q = ta.input.value === ta.label ? '' : ta.input.value;
      var list = taList(q);
      if (ta.hi >= list.length) ta.hi = list.length - 1;
      res.innerHTML = list.length ? list.map(function (x, n) {
        return '<div class="appt-cust-row' + (n === ta.hi ? ' on' : '') + '" role="option" data-id="' + esc(x.id) + '"><div>' + esc(x.name) + '</div><div class="meta">' + esc(svcMeta(x)) + '</div></div>';
      }).join('') : '<div class="appt-cust-row" style="cursor:default;opacity:.55">No services match “' + esc(q) + '”.</div>';
      ta.list = list;
      ta.input.setAttribute('aria-expanded', 'true');
      var on = res.querySelector('.on'); if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest' });
    }
    function taClose(restore) {
      if (!ta) return;
      var res = ta.input.parentNode.querySelector('.wi-ta-res'); if (res) res.remove();
      ta.input.setAttribute('aria-expanded', 'false');
      if (restore) ta.input.value = ta.label;
      ta = null;
    }
    function taPick(id) {
      if (!ta) return;
      var i = ta.i, k = ta.k;
      taClose(false);
      state.rows[i].svcs[k] = String(id);
      renderItems(); itemsChanged(i === 0 && k === 0);
    }
    box.addEventListener('focusin', function (e) {
      if (!e.target.classList.contains('wi-ta-in')) return;
      if (ta && ta.input !== e.target) taClose(true);
      var row = e.target.closest('.wi-row');
      ta = { input: e.target, i: +row.dataset.i, k: +e.target.dataset.k, hi: -1, label: e.target.value, list: [] };
      e.target.select();
      taRender();
    });
    box.addEventListener('focusout', function (e) {
      if (!e.target.classList.contains('wi-ta-in')) return;
      setTimeout(function () { if (ta && document.activeElement !== ta.input) taClose(true); }, 150);
    });
    box.addEventListener('mousedown', function (e) {
      var opt = e.target.closest('.wi-ta-res .appt-cust-row[data-id]');
      if (opt) { e.preventDefault(); taPick(opt.dataset.id); }
    });
    box.addEventListener('keydown', function (e) {
      if (!ta || e.target !== ta.input) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); ta.hi = Math.min((ta.list.length || 1) - 1, ta.hi + 1); taRender(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); ta.hi = Math.max(0, ta.hi - 1); taRender(); }
      else if (e.key === 'Enter') { e.preventDefault(); var pick = ta.list[ta.hi >= 0 ? ta.hi : 0]; if (pick) taPick(pick.id); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); taClose(true); e.target.blur(); }
    });
    box.addEventListener('input', function (e) {
      if (ta && e.target === ta.input) { ta.hi = 0; taRender(); return; }
      var row = e.target.closest('.wi-row'); if (!row) return;
      var r = state.rows[+row.dataset.i];
      if (e.target.dataset.f === 'name') r.name = e.target.value;
      if (e.target.dataset.f === 'ident') r.ident = e.target.value;
    });
    box.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-f]'); if (!b) return;
      var i = +b.closest('.wi-row').dataset.i, r = state.rows[i];
      if (b.dataset.f === 'rm') { state.rows.splice(i, 1); renderItems(); itemsChanged(i === 0); }
      if (b.dataset.f === 'rmsvc') { r.svcs.splice(+b.dataset.k, 1); renderItems(); itemsChanged(false); }
      if (b.dataset.f === 'addsvc') {
        r.svcs.push(r.svcs[0] || ''); renderItems(); itemsChanged(false);
        var ss = box.querySelectorAll('.wi-row[data-i="' + i + '"] .wi-ta-in'); if (ss.length) ss[ss.length - 1].focus();
      }
    });
  })();

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
        rsel.style.display = 'none';
        renderTiles('appt-sp-resource-tiles', 'appt-sp-resource',
          resources.map(function (r) { return { value: r.id, label: r.name, sub: r.subtitle || '' }; }), '');
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
    // service and resource are both chosen by now, so
    // there is nothing left for a button to ask. The button stays in the DOM
    // as the manual refresh for when someone wants to force it.
    el('appt-sp-find-section').style.display = 'none';
    if (resourceId) {
      onFindTimes();
      startAutoRefresh();
    } else {
      stopAutoRefresh();
    }
  }

  function onFindTimes() {
    if (!state.selectedServiceId || !state.selectedResourceId) return;
    state.weekStartDate = state.weekStartDate || todayStr();
    if (bookingMode === 'drop_off') { fetchDayLoad(); } else { fetchWeekTimes(); }
  }

  // ---------------------------------------------------------- choice tiles
  // The column count follows the number of options, so three choices read as
  // a row and four read as a square instead of both being a ragged grid.
  function tileColumns(n) {
    if (n <= 1) { return 1; }
    if (n === 2) { return 1; }   // two full-width rows
    if (n === 3) { return 3; }
    if (n === 4) { return 2; }
    return 3;                    // 5, 6 and beyond
  }

  // opts: [{value, label, sub}] — renders into host, writes through to select.
  function renderTiles(hostId, selectId, opts, placeholderValue) {
    var host = el(hostId);
    var sel  = el(selectId);
    if (!host || !sel) { return; }

    if (!opts.length) { host.style.display = 'none'; host.innerHTML = ''; return; }

    var cols = tileColumns(opts.length);
    var filter = opts.length >= 13;
    var html = '';

    if (filter) {
      html += '<input type="text" class="appt-input appt-tiles-filter" placeholder="Filter…" '
            + 'data-for="' + hostId + '" style="margin-bottom:8px">';
    }
    html += '<div class="appt-tiles-grid" style="grid-template-columns:repeat(' + cols + ',1fr)">';
    opts.forEach(function (o) {
      var on = String(sel.value || '') === String(o.value);
      html += '<button type="button" class="appt-tile' + (on ? ' on' : '') + (o.isAdd ? ' add' : '') + '"'
        + ' data-value="' + escapeHtml(String(o.value)) + '"'
        + ' data-label="' + escapeHtml((o.label + ' ' + (o.sub || '')).toLowerCase()) + '">'
        + '<span class="appt-tile-l">' + escapeHtml(o.label) + '</span>'
        + (o.sub ? '<span class="appt-tile-s">' + escapeHtml(o.sub) + '</span>' : '')
        + '</button>';
    });
    html += '</div>';
    host.innerHTML = html;
    host.style.display = 'block';

    host.querySelectorAll('.appt-tile').forEach(function (b) {
      b.addEventListener('click', function () {
        sel.value = b.dataset.value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        host.querySelectorAll('.appt-tile').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
      });
    });

    var f = host.querySelector('.appt-tiles-filter');
    if (f) {
      f.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        host.querySelectorAll('.appt-tile').forEach(function (b) {
          b.style.display = b.dataset.label.indexOf(q) === -1 ? 'none' : '';
        });
      });
    }

    // A single real choice is preselected, but still shown: an invisible
    // default is how the wrong one gets booked.
    var real = opts.filter(function (o) { return !o.isAdd && String(o.value) !== String(placeholderValue || ''); });
    if (real.length === 1 && !sel.value) {
      sel.value = real[0].value;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      var only = host.querySelector('.appt-tile[data-value="' + real[0].value + '"]');
      if (only) { only.classList.add('on'); }
    }
  }

  // ------------------------------------------------------ auto refresh
  // Every rule here exists because of a way this can go wrong: a refresh that
  // moves the day you picked, wipes the reason you typed, runs in a hidden
  // tab, or outlives the modal it belongs to.
  function startAutoRefresh() {
    stopAutoRefresh();
    if (!autoRefresh) { renderRefreshState(); return; }
    refreshTimer = setInterval(function () {
      if (document.hidden) { return; }
      if (!state.selectedServiceId || !state.selectedResourceId) { return; }
      silentRefresh();
    }, 60000);
    renderRefreshState();
  }

  function stopAutoRefresh() {
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
  }

  function silentRefresh() {
    var keptDate   = state.selectedDay ? state.selectedDay.date : null;
    var keptSlot   = state.selectedSlot;
    var reasonEl   = el('appt-ov-reason');
    var keptReason = reasonEl ? reasonEl.value : null;
    var wasState   = state.selectedDay ? state.selectedDay.state : null;

    var done = function () {
      // Put the selection back exactly as it was.
      if (keptDate) {
        var again = null;
        state.dayLoad.forEach(function (d) { if (d.date === keptDate) { again = d; } });
        if (again) {
          state.selectedDay = again;
          state.selectedSlot = keptSlot;
          if (bookingMode === 'drop_off') { renderDayLoad(); }
          if (again.state !== wasState) { noteDayChanged(again, wasState); }
        }
      }
      var r2 = el('appt-ov-reason');
      if (r2 && keptReason) { r2.value = keptReason; }
    };

    if (bookingMode === 'drop_off') { fetchDayLoad(done); } else { fetchWeekTimes(done); }
  }

  function noteDayChanged(d, was) {
    var box = el('appt-day-warn');
    if (!box) { return; }
    var msg = d.state === 'full'
      ? escapeHtml(d.long_label) + ' filled up while this was open — it is now ' + d.used + ' of ' + d.max + '.'
      : escapeHtml(d.long_label) + ' changed while this was open.';
    var note = document.createElement('div');
    note.className = 'appt-day-changed';
    note.textContent = msg;
    box.parentNode.insertBefore(note, box);
  }

  function renderRefreshState() {
    var host = el('appt-sp-refresh-state');
    if (!host) { return; }
    host.innerHTML = autoRefresh
      ? 'Auto refresh on · <a href="' + settingsUrl + '" target="_blank" rel="noopener">settings</a>'
      : 'Auto refresh off · <a href="' + settingsUrl + '" target="_blank" rel="noopener">settings</a>';
  }

  // ---------------------------------------------------------- day picker
  function fetchDayLoad(after) {
    var listEl = el('appt-sp-times-list');
    listEl.innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('appt-sp-times-section').style.display = 'block';
    el('appt-sp-times-head-label').textContent = 'Which day';
    listEl.classList.add('is-days');
    el('appt-sp-week-label').textContent = formatWeekLabel(state.weekStartDate);
    el('appt-sp-prev-week').disabled = (state.weekStartDate <= todayStr());

    var url = routes.dayLoad
      + '?start=' + encodeURIComponent(state.weekStartDate)
      + '&days=7'
      + '&service_id=' + encodeURIComponent(state.selectedServiceId || '');

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(function (data) {
        state.dayLoad = data.days || [];
        dayPolicy = data.policy || dayPolicy;
        renderDayLoad();
        if (typeof after === 'function') { after(); }
      })
      .catch(function (e) {
        listEl.innerHTML = '<div class="appt-sp-times-empty error">Could not read the days ('
          + escapeHtml(e && e.message ? e.message : 'no response') + '). Try again.</div>';
      });
  }

  function dayIsPickable(d) {
    if (d.state === 'closed') { return false; }
    if (d.state === 'too_soon') { return !!dayPolicy.short_notice; }
    if (d.state === 'full') { return !!dayPolicy.overbook; }
    return true;
  }

  function dayCaption(d) {
    if (d.state === 'closed') { return 'Closed'; }
    if (d.max === null) { return d.state === 'too_soon' ? 'Too soon' : 'Open'; }
    if (d.state === 'full') { return 'Full · ' + d.used + ' of ' + d.max; }
    if (d.state === 'too_soon') { return 'Too soon · ' + d.left + ' left'; }
    return d.left + ' of ' + d.max + ' left';
  }

  function renderDayLoad() {
    var listEl = el('appt-sp-times-list');
    if (!state.dayLoad.length) {
      listEl.innerHTML = '<div class="appt-sp-times-empty">Nothing in this week.</div>';
      return;
    }
    var html = '<div class="appt-day-grid">';
    state.dayLoad.forEach(function (d, idx) {
      var pickable = dayIsPickable(d);
      var cls = 'appt-day';
      if (d.state === 'too_soon') { cls += ' notice'; }
      if (d.state === 'full') { cls += ' over'; }
      if (d.state === 'closed' || !pickable) { cls += ' shut'; }
      if (state.selectedDay && state.selectedDay.date === d.date) { cls += ' sel'; }
      var pct = (d.max && d.max > 0) ? Math.min(100, Math.round((d.used / d.max) * 100)) : 0;
      html += '<div class="' + cls + '" data-idx="' + idx + '">'
        + (d.is_today ? '<span class="appt-day-flag">Today</span>' : '')
        + '<div class="appt-day-d">' + escapeHtml(d.label) + '</div>'
        + '<div class="appt-day-c">' + escapeHtml(dayCaption(d)) + '</div>'
        + '<div class="appt-day-bar"><i style="width:' + pct + '%"></i></div>'
        + '</div>';
    });
    html += '</div>';
    html += '<div id="appt-day-warn"></div>';
    listEl.innerHTML = html;

    listEl.querySelectorAll('.appt-day').forEach(function (card) {
      card.addEventListener('click', function () {
        var d = state.dayLoad[parseInt(card.dataset.idx, 10)];
        if (!dayIsPickable(d)) { return; }
        state.selectedDay = d;
        // In drop_off there is no time; the day IS the selection.
        state.selectedSlot = { date: d.date, time: null, resource_id: state.selectedResourceId };
        renderDayLoad();
        renderDayWarning();
        if (typeof updateCreateEnabled === 'function') { updateCreateEnabled(); }
      });
    });
    renderDayWarning();
  }

  function renderDayWarning() {
    var box = el('appt-day-warn');
    if (!box) { return; }
    var d = state.selectedDay;
    if (!d) { box.innerHTML = ''; return; }

    var tooSoon = d.state === 'too_soon';
    var full    = d.state === 'full';
    if (!tooSoon && !full) {
      box.innerHTML = '<div class="appt-day-note">' + escapeHtml(d.long_label)
        + (d.max === null ? '' : ' · ' + (d.left - 1 < 0 ? 0 : d.left - 1) + ' of ' + d.max + ' left after this')
        + '</div>';
      return;
    }

    var lines = '';
    if (tooSoon && dayPolicy.warns) {
      lines += '<label class="appt-day-sw"><input type="checkbox" class="appt-ov-notice" checked> '
        + 'Ignore the ' + dayPolicy.notice_hours + '-hour notice</label>';
    }
    if (full) {
      lines += '<label class="appt-day-sw"><input type="checkbox" class="appt-ov-cap" checked> '
        + 'Go over the limit — this makes ' + (d.used + 1) + ' on a day sized for ' + d.max + '</label>';
    }

    box.innerHTML = '<div class="appt-day-warn' + (full ? ' bad' : '') + '">'
      + '<b>' + (tooSoon && full
          ? 'Today is sooner than usual and already full.'
          : (full ? escapeHtml(d.long_label) + ' is already full.'
                  : 'Sooner than you normally take bookings.')) + '</b>'
      + '<div class="appt-day-sub">' + lines + '</div>'
      + '<input type="text" id="appt-ov-reason" class="appt-input" placeholder="Why (optional, shows on the calendar)">'
      + '</div>';
  }

  function reloadWhenWeekChanges() {
    // same nav, either picker.
    if (bookingMode === 'drop_off') { fetchDayLoad(); } else { fetchWeekTimes(); }
  }

  function fetchWeekTimes(after) {
    var listEl = el('appt-sp-times-list');
    listEl.classList.remove('is-days');
    listEl.innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('appt-sp-times-section').style.display = 'block';
    state.availLoading = true;
    el('appt-sp-week-label').textContent = formatWeekLabel(state.weekStartDate);
    el('appt-sp-prev-week').disabled = (state.weekStartDate <= todayStr());

    var url = routes.weekTimes
      + '?service_id='  + encodeURIComponent(state.selectedServiceId)
      + '&extra_service_ids=' + encodeURIComponent(extraServiceIds().join(','))
      + '&resource_id=' + encodeURIComponent(state.selectedResourceId)
      + '&start_date='  + encodeURIComponent(state.weekStartDate);
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.availLoading = false;
        state.availSlots = data.slots || [];
        state.shortNoticeWarns = !!data.short_notice_warns;
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
        // a time inside the notice window is offered
        // to staff, but it says what it is. Silent policy offers it unmarked.
        + (slot.short_notice && state.shortNoticeWarns
            ? '<span class="appt-sp-short">short notice</span>' : '')
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
        if (window.apptPdFilter) window.apptPdFilter(slot.date);
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
    reloadWhenWeekChanges();
  }

  function onNextWeek() {
    if (!state.weekStartDate) state.weekStartDate = todayStr();
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() + 7);
    state.weekStartDate = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    reloadWhenWeekChanges();
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
  // askNotify() lived here and is gone.
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
      // asked for here, granted by the tenant policy server-side.
      override_short_notice: !!(document.querySelector('.appt-ov-notice') || {}).checked,
      override_capacity:     !!(document.querySelector('.appt-ov-cap') || {}).checked,
      override_reason:       (el('appt-ov-reason') && el('appt-ov-reason').value) || null,
      route_window_id: (el('appt-pd-window') && el('appt-pd-window').value) || null,
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
      payload.customer_address_line1 = el('appt-addr').value.trim();
      payload.customer_city          = el('appt-city').value.trim();
      payload.customer_state         = el('appt-state').value.trim();
      payload.customer_postcode      = el('appt-post').value.trim();
      if (!payload.customer_first_name || !payload.customer_last_name || !payload.customer_email) {
        showError('First name, last name, and email are required for a new customer.');
        btn.disabled = false; btn.innerHTML = 'Save appointment';
        return;
      }
    }

    if (assetsCfg.enabled) {
      // every item, each with its own services; the first service is the one the time was found for
      var _assets = [], _items = [];
      for (var _i = 0; _i < state.rows.length; _i++) {
        var _r = state.rows[_i], _n = _i + 1, _w = assetsCfg.singular;
        if (!_r.item || (_r.item === '__new__' && !_r.name.trim())) { showError((state.rows.length > 1 ? cap(_w) + ' ' + _n : 'The ' + _w) + ' needs one picked or a name.'); btn.disabled = false; btn.innerHTML = 'Save appointment'; return; }
        if (!_r.svcs.filter(Boolean).length || _r.svcs.some(function (x) { return !x; })) { showError('Pick a service for ' + (state.rows.length > 1 ? _w + ' ' + _n : 'the ' + _w) + ', or remove the empty one.'); btn.disabled = false; btn.innerHTML = 'Save appointment'; return; }
        _assets.push(_r.item === '__new__' ? { client_key: _r.key, name_snapshot: _r.name.trim(), identifier: _r.ident.trim() || null } : { client_key: _r.key, customer_asset_id: _r.item });
        _r.svcs.forEach(function (id) { _items.push({ service_item_id: id, price_override_cents: null, asset_client_key: _r.key }); });
      }
      payload.assets = _assets;
      payload.items = _items;
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
        // saved, and nobody has been told.
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
    toggleServicePicker: toggleServicePicker, submit: submit, addItem: addItem,
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
