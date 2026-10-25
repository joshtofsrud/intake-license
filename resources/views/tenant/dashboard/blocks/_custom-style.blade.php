{{-- MARKER-DASH-BUILDER: styles for made-here blocks (custom.blade.php), also
     used by master admin's block builder preview. --}}
    <style>
      .cb { margin: 0 0 28px; }
      .cb-h { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
      .cb-h h2 { font-size: 21px; font-weight: 600; letter-spacing: -.02em; margin: 0; }
      .cb-h .meta { margin-left: auto; font-size: 12.5px; color: var(--ia-text-muted, #8b8b93); }
      .cb-h .meta a { color: inherit; }
      .cb-line { font-size: 13px; color: var(--ia-text-muted, #8b8b93); margin-top: 4px; }
      .cb-big { display: flex; align-items: baseline; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
      .cb-big .v { font-size: 38px; font-weight: 600; letter-spacing: -.03em; line-height: 1; font-variant-numeric: tabular-nums; }
      .cb-big .u { font-size: 13px; color: var(--ia-text-muted, #8b8b93); }
      .cb-big .d { font-size: 12.5px; font-weight: 600; }
      .cb-big .d.up { color: #4ade80; } .cb-big .d.down { color: #f87171; }
      .cb-big .was { font-size: 12.5px; color: var(--ia-text-muted, #8b8b93); }
      .cb-spark { margin-top: 10px; height: 36px; color: var(--ia-accent, #f5a524); }
      .cb-spark svg { width: 100%; height: 36px; display: block; }
      .cb-rows { margin-top: 8px; }
      .cb-row { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; padding: 11px 6px 11px 0; border-bottom: 1px solid var(--ia-border, rgba(255,255,255,.08)); color: inherit; text-decoration: none; }
      a.cb-row:hover { background: var(--ia-hover, rgba(255,255,255,.03)); }
      .cb-row .t { font-size: 15px; font-weight: 500; }
      .cb-row .s { font-size: 13px; color: var(--ia-text-muted, #8b8b93); }
      .cb-row .r { font-size: 13.5px; font-variant-numeric: tabular-nums; }
      .cb-row .arr { color: var(--ia-text-muted, #8b8b93); font-size: 18px; }
      .cb-calm { padding: 14px 0; color: var(--ia-text-muted, #8b8b93); font-size: 14px; }
      .cb-acts { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
      .cb-note { border-left: 2px solid var(--ia-accent, #f5a524); background: var(--ia-accent-soft, rgba(245,165,36,.08)); border-radius: var(--ia-r-md, 8px); padding: 14px 18px; }
      .cb-note .t { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
      .cb-note .s { font-size: 14px; line-height: 1.55; white-space: pre-line; opacity: .85; }
    </style>
