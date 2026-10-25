{{-- MARKER-DASH-ADMIN: shared look for the Dashboards pages. The Sales pages'
     line-and-row vocabulary (.sx-root), so fixes.css's light-mode token
     overrides apply here too. --}}
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; --db-txt:#fff; --db-vtext:#a78bfa;
    --db-amber:#fbbf24; --db-red:#f47c7c; --db-hover:rgba(255,255,255,.03); --db-card:rgba(255,255,255,.025); font-size:14px; }
  html:not(.dark) .sx-root { --db-txt:#18181b; --db-vtext:#6d28d9; --db-amber:#b45309; --db-red:#dc2626; --db-hover:rgba(0,0,0,.025); --db-card:#fff; }
  .db-legend { color:var(--sx-faint); font-size:12.5px; line-height:1.6; padding:0 0 12px; border-bottom:1px solid var(--sx-line); }
  .db-legend b { color:var(--sx-dim); font-weight:500; }
  .db-t { width:100%; border-collapse:collapse; font-size:13.5px; }
  .db-t th { text-align:left; font-weight:500; color:var(--sx-faint); font-size:12.5px; padding:10px; border-bottom:1px solid var(--sx-line-2); white-space:nowrap; }
  .db-t td { padding:12px 10px; border-bottom:1px solid var(--sx-line); vertical-align:middle; }
  .db-t tr.on td { background:var(--sx-vsoft); }
  .db-t tr.dim td { opacity:.55; }
  .db-t label { display:flex; align-items:center; gap:10px; cursor:pointer; }
  .db-t input[type=radio] { accent-color:#8b5cf6 !important; color:#8b5cf6 !important; width:15px; height:15px; }
  .db-t td.r { text-align:right; white-space:nowrap; }
  .db-nm { font-weight:600; color:var(--db-txt); }
  .db-sub { font-size:12px; color:var(--sx-faint); margin-top:2px; }
  .db-tag { display:inline-block; border-radius:4px; padding:1px 7px; font-size:11px; font-weight:600; white-space:nowrap; line-height:1.6; }
  .db-tag.live { background:rgba(190,242,100,.14); color:var(--sx-lime); }
  .db-tag.draft { border:1px solid var(--sx-line-2); color:var(--sx-dim); font-weight:500; }
  .db-tag.pend { background:rgba(251,191,36,.13); color:var(--db-amber); }
  .db-tag.off { border:1px dashed var(--sx-line-2); color:var(--sx-faint); font-weight:500; }
  .db-beta { font-size:9.5px; font-weight:700; letter-spacing:.06em; opacity:.7; }
  .db-num { font-variant-numeric:tabular-nums; }
  .db-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:14px 0; }
  .db-bar .note, .db-note { font-size:12.5px; color:var(--sx-faint); line-height:1.55; }
  .db-bar .note b, .db-note b { color:var(--db-txt); font-weight:600; }
  .db-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:6px 13px; font-weight:500; font-size:13px; cursor:pointer; color:inherit; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
  .db-btn:hover { background:var(--db-hover); }
  .db-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .db-btn.sm { padding:3px 9px; font-size:12px; }
  .db-btn.warn { color:var(--db-red); }
  .db-btn[disabled] { opacity:.45; cursor:default; }
  .db-lnk { background:none; border:0; padding:0; font:inherit; color:var(--db-vtext); cursor:pointer; text-decoration:none; }
  .db-lnk:hover { text-decoration:underline; }
  .db-lnk.dim { color:var(--sx-dim); }
  .db-lnk.red { color:var(--db-red); }
  .db-acts { display:inline-flex; gap:14px; align-items:center; }
  .db-in { background-color:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; width:100%; }
  html:not(.dark) .db-in { background-color:#fff; color:#18181b; }
  .db-in option { background:#26272c; } html:not(.dark) .db-in option { background:#fff; }
  .db-h3 { font-size:13px; font-weight:600; color:var(--sx-dim); margin:24px 0 6px; display:flex; gap:10px; align-items:baseline; }
  .db-h3 .meta { margin-left:auto; font-weight:400; color:var(--sx-faint); font-size:12.5px; }
  .db-warn { color:var(--db-amber); }
  .db-diff { display:flex; gap:6px 14px; flex-wrap:wrap; align-items:center; font-size:12.5px; color:var(--sx-faint); padding:10px 0; border-bottom:1px solid var(--sx-line); min-height:41px; }
  .db-diff .added { color:var(--sx-lime); } .db-diff .removed { color:var(--db-red); } .db-diff .moved, .db-diff .layout { color:var(--db-amber); }
  @media (max-width:860px) { .db-hide-m { display:none; } .db-t td, .db-t th { padding-left:6px; padding-right:6px; } }
</style>
