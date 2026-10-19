{{-- MARKER-SHOP-MENU-GROUPS: dropdown + phone accordion styles; only on sites that have groups --}}
.p-dd { position: relative; display: inline-flex; }
.p-dd-btn { background: none; border: 0; font: inherit; cursor: pointer; }
.p-dd-btn svg { transition: transform .2s; opacity: .7; }
.p-dd.on .p-dd-btn { opacity: 1; }
.p-dd.on .p-dd-btn svg { transform: rotate(180deg); }
.p-dd-panel { position: absolute; top: calc(100% + 16px); left: 50%; z-index: 160; transform: translateX(-50%) translateY(-6px);
  opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .18s ease, transform .18s ease, visibility .18s;
  display: flex; gap: 10px; padding: 10px; width: max-content; max-width: min(720px, 92vw);
  border-radius: calc(var(--p-card-r, 12px) + 6px); background: var(--p-dd-bg, #ffffff); color: var(--p-dd-text, #0a0a0a);
  border: .5px solid color-mix(in srgb, var(--p-dd-text, #0a0a0a) 14%, transparent); box-shadow: 0 26px 56px rgba(0,0,0,.28); text-align: left; }
.p-dd-panel::before { content: ''; position: absolute; left: 0; right: 0; top: -18px; height: 18px; }
.p-dd.on .p-dd-panel { opacity: 1; visibility: visible; transform: translateX(-50%); pointer-events: auto; }
.p-dd-items { display: grid; grid-template-columns: 1fr; gap: 2px; min-width: 240px; }
.p-dd-items.is-two { grid-template-columns: 1fr 1fr; width: 470px; }
.p-dd-item, .p-mg-item { display: flex; gap: 12px; align-items: flex-start; padding: 10px 12px; border-radius: 12px; text-decoration: none; color: inherit; }
.p-dd-item:hover, .p-dd-item:focus-visible { background: color-mix(in srgb, var(--p-dd-text, #0a0a0a) 7%, transparent); outline: 0; }
.p-dd-ic { flex: none; width: 32px; height: 32px; border-radius: 9px; background: color-mix(in srgb, var(--p-accent) 16%, transparent); color: var(--p-accent); display: flex; align-items: center; justify-content: center; }
.p-dd-ic svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.p-dd-item b, .p-mg-item b { display: block; font-size: 14px; font-weight: 600; line-height: 1.3; }
.p-dd-item small, .p-mg-item small { display: block; font-size: 12.5px; opacity: .62; margin-top: 2px; line-height: 1.4; font-weight: 400; }
.p-dd-feat { position: relative; width: 190px; border-radius: 12px; padding: 14px; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 6px;
  background: linear-gradient(160deg, color-mix(in srgb, var(--p-accent) 20%, transparent), color-mix(in srgb, var(--p-accent) 5%, transparent)); }
.p-dd-feat b { font-size: 14.5px; margin-top: auto; }
.p-dd-feat small { font-size: 12.5px; opacity: .65; line-height: 1.4; padding-right: 14px; }
.p-dd-feat i { position: absolute; right: 14px; bottom: 14px; font-style: normal; color: var(--p-accent); }
.p-mg summary { list-style: none; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
.p-mg summary::-webkit-details-marker { display: none; }
.p-mg summary i { font-style: normal; color: var(--p-accent); font-size: 22px; line-height: 1; transition: transform .2s; }
.p-mg[open] summary i { transform: rotate(45deg); }
.p-mg-in { display: grid; gap: 0; padding: 4px 0 8px; }
.p-mg .p-mg-item { padding: 9px 4px; }
.p-mg-feat b { color: var(--p-accent); }
.p-nav-drop .p-mg summary { padding: 12px 2px; font-size: 16px; font-weight: 500; color: var(--pf-menu-link, inherit); border-bottom: 1px solid color-mix(in srgb, var(--pf-menu-link, currentColor) 12%, transparent); }
.p-nav-drop .p-mg .p-mg-item { border: 0 !important; padding: 9px 4px !important; font-size: inherit !important; color: var(--pf-menu-link, inherit) !important; }
.p-nav-drop .p-mg[open] { border-bottom: 1px solid color-mix(in srgb, var(--pf-menu-link, currentColor) 12%, transparent); }
.p-nav-drop .p-mg[open] summary { border-bottom: 0; }
#p-mobile-nav .p-mg summary { font-size: 22px; font-weight: 600; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,.07); }
#p-mobile-nav .p-mg .p-mg-item { font-size: inherit; font-weight: inherit; border: 0; padding: 9px 4px; }
#p-mobile-nav .p-mg[open] summary { border-bottom: 0; }
#p-mobile-nav .p-mg[open] { border-bottom: 1px solid rgba(0,0,0,.07); }
@media (max-width: 768px) { .p-dd-panel { display: none; } }
