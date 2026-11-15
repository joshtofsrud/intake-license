{{-- shared portal styles, pushed by each portal view.
     MARKER-PORTAL-RENTALS: Studio layout (big greeting, colour tiles, open
     lists with dividers instead of boxed cards), driven entirely by the shop's
     site tokens (--p-*) so it matches their website in light AND dark. Status
     colours are mixed against the shop's own text colour, never fixed light-mode
     hex, so pills stay readable on dark sites. Radii follow the shop's card and
     button radius settings (see _shell). --}}
<style>
.ac-body{--ok:#3f8f2a;--info:#2f74c0;--warn:#c27a12;--bad:#c2362b;
  --ac-t1:#EDCB5A;--ac-t2:#EBB0C8;--ac-t3:#B3CF66;--ac-t4:#A8BCE6}

/* ---- section nav */
.ac-nav{display:flex;gap:2px;border-bottom:1px solid var(--p-border);margin:-6px 0 26px;overflow-x:auto;scrollbar-width:none}
.ac-nav a{padding:9px 13px;font-size:13.5px;opacity:.5;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.ac-nav a:hover{opacity:.8}
.ac-nav a.on{opacity:1;border-bottom-color:var(--p-accent);font-weight:600}

/* ---- greeting */
.ac-hi{margin-bottom:22px}
.ac-hi h1{font-family:var(--p-font-heading);font-weight:var(--p-heading-weight,600);font-size:30px;letter-spacing:-.02em;line-height:1.15}
.ac-hi p{font-size:13.5px;opacity:.5;margin-top:4px}

/* ---- tiles (Studio). Tone mixed into the shop's own background. */
.ac-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:30px}
.ac-tile{--c:var(--ac-t1);display:flex;flex-direction:column;gap:6px;min-height:132px;padding:16px 16px 14px;border-radius:var(--p-r-lg);
  color:var(--p-text);background:color-mix(in srgb,var(--c) 26%,var(--p-bg));box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--c) 30%,transparent);transition:transform .15s}
.ac-tile:hover{transform:translateY(-1px)}
.ac-t2{--c:var(--ac-t2)} .ac-t3{--c:var(--ac-t3)} .ac-t4{--c:var(--ac-t4)}
.ac-tile-k{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.65;display:flex;align-items:center;gap:6px}
.ac-tile-v{font-size:19px;font-weight:700;line-height:1.2;font-family:var(--p-font-heading)}
.ac-tile-p{font-size:14px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.ac-tile-s{font-size:12px;opacity:.6;margin-top:auto}
.ac-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--p-accent)}
@media (max-width:520px){.ac-tiles{grid-template-columns:1fr}.ac-tile{min-height:0}}

/* ---- open lists: dividers, no boxes */
.ac-section-title{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.5;margin:0 0 8px}
.ac-list{border-top:1px solid var(--p-border);margin-bottom:26px}
.ac-list + .ac-list{margin-top:-26px;border-top:0} /* back-to-back blocks read as one list */
.ac-list-row{padding:13px 2px;border-bottom:1px solid var(--p-border);display:flex;align-items:center;justify-content:space-between;font-size:14px;gap:12px;color:inherit;text-decoration:none}
a.ac-list-row:hover{background:color-mix(in srgb,var(--p-text) 4%,transparent)}
a.ac-list-row .ac-go{opacity:.35;font-size:17px;margin-left:8px}
.ac-list-name{font-weight:500}
.ac-list-meta{font-size:12px;opacity:.55;margin-top:2px}
.ac-list-right{text-align:right;flex-shrink:0;margin-left:12px}
.ac-empty{padding:26px 2px;text-align:center;font-size:14px;opacity:.45;border-bottom:1px solid var(--p-border)}
.ac-pager{display:flex;justify-content:space-between;margin:-14px 0 24px;font-size:13px}
.ac-pager a{font-weight:600}

/* ---- pills: tone tinted on the shop's background, text mixed with the shop's text */
.ac-pill{--tone:var(--p-text);display:inline-flex;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap;
  background:color-mix(in srgb,var(--tone) 16%,transparent);color:color-mix(in srgb,var(--tone) 75%,var(--p-text))}
.ac-pill--registered,.ac-pill--checked_in,.ac-pill--confirmed,.ac-pill--active,.ac-pill--paid{--tone:var(--ok)}
.ac-pill--pending{--tone:var(--info)}
.ac-pill--waitlisted,.ac-pill--in_progress,.ac-pill--due{--tone:var(--warn)}
.ac-pill--refunded{--tone:var(--bad)}
.ac-pill--no_show,.ac-pill--cancelled,.ac-pill--completed,.ac-pill--returned{--tone:var(--p-text);opacity:.7}

/* ---- banners */
.ac-banner{display:flex;justify-content:space-between;align-items:center;gap:12px;border-radius:var(--p-r);padding:12px 15px;margin-bottom:14px;font-size:13.5px;
  --tone:var(--bad);background:color-mix(in srgb,var(--tone) 14%,var(--p-bg));color:color-mix(in srgb,var(--tone) 70%,var(--p-text));border:1px solid color-mix(in srgb,var(--tone) 30%,transparent)}
.ac-banner b{font-weight:700}
.ac-banner a{font-weight:600;white-space:nowrap;border-bottom:1px solid currentColor}

/* ---- quick links */
.ac-quick{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
.ac-quick a{border:1.5px solid var(--p-border);border-radius:var(--p-r);padding:9px 14px;font-weight:600;font-size:13.5px}
.ac-quick a:hover{border-color:var(--p-text)}

/* ---- messages */
.ac-msg-wrap{border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden;margin-bottom:14px}
.ac-msgs{padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--p-surface);max-height:480px;overflow-y:auto}
.ac-msg{max-width:78%;padding:9px 12px;border-radius:12px;font-size:13.5px;line-height:1.45;background:var(--p-bg);border:1px solid var(--p-border);word-break:break-word;white-space:pre-wrap}
.ac-msg.me{align-self:flex-end;background:var(--p-accent);color:var(--p-accent-text);border-color:transparent;border-bottom-right-radius:4px}
.ac-msg.shop{align-self:flex-start;border-bottom-left-radius:4px}
.ac-msg.txn{align-self:stretch;max-width:none;background:transparent;border-style:dashed;font-size:12px;opacity:.65;white-space:normal}
.ac-msg-t{font-size:10px;opacity:.55;margin-top:4px;white-space:normal}
.ac-chan{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.07em;padding:1px 5px;border-radius:4px;background:color-mix(in srgb,currentColor 14%,transparent);margin-right:5px;vertical-align:1px}
.ac-about{display:inline-block;font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:10px;background:color-mix(in srgb,currentColor 16%,transparent);margin-bottom:4px;color:inherit}
.ac-compose{display:flex;gap:8px;padding:12px;border-top:1px solid var(--p-border)}
.ac-compose textarea{flex:1;padding:10px 13px;border:1.5px solid var(--p-border);border-radius:14px;font-family:inherit;font-size:14px;background:transparent;color:inherit;resize:none}
.ac-compose button{border:0;background:var(--p-accent);color:var(--p-accent-text);border-radius:50%;width:42px;height:42px;font-size:16px;font-weight:700;flex:0 0 auto;align-self:flex-end;cursor:pointer}
.ac-legend{font-size:12px;opacity:.5;margin:0 0 22px}

/* ---- detail pages (work order, purchase, rental) */
.ac-back{display:inline-block;font-size:13px;opacity:.55;margin-bottom:12px}
.ac-back:hover{opacity:.9}
.ac-dhead{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:18px}
.ac-dtitle{font-family:var(--p-font-heading);font-weight:var(--p-heading-weight,700);font-size:26px;letter-spacing:-.015em;line-height:1.2}
.ac-dsub{font-size:13px;opacity:.75;margin-top:5px}
.ac-dact{display:flex;gap:6px;flex-shrink:0}
.ac-dact a{font-size:12.5px;font-weight:600;padding:6px 12px;border-radius:var(--p-btn-r,20px);border:1.5px solid var(--p-border);white-space:nowrap}
.ac-dact a:hover{border-color:var(--p-text)}
.ac-steps{display:flex;margin:4px 0 20px;gap:4px}
.ac-step{flex:1;padding:9px 6px 8px;text-align:center;font-size:12px;border-top:3px solid var(--p-border);opacity:.45}
.ac-step.done{opacity:.8;border-top-color:color-mix(in srgb,var(--p-accent) 55%,var(--p-border))}
.ac-step.now{opacity:1;border-top-color:var(--p-accent);font-weight:700}
.ac-step small{display:block;font-size:10.5px;font-weight:400;opacity:.75;margin-top:1px}
.ac-kv{display:grid;grid-template-columns:auto 1fr;gap:7px 18px;font-size:13.5px;margin-bottom:24px}
.ac-kv dt{opacity:.55}
.ac-kv dd{margin:0}
.ac-grp{padding:10px 2px 6px;font-size:13px;font-weight:700;border-bottom:1px solid var(--p-border)}
.ac-grp span{font-weight:400;opacity:.55;margin-left:6px}
.ac-ans{padding:8px 2px;border-bottom:1px solid var(--p-border);font-size:12.5px}
.ac-ans b{font-weight:600;opacity:.6;margin-right:6px}
.ac-line{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 2px;border-bottom:1px solid var(--p-border);font-size:13.5px}
.ac-line-m{font-size:11.5px;opacity:.55;margin-top:1px}
.ac-line-r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.ac-thumb{flex:0 0 52px;height:52px;border-radius:var(--p-r);background:var(--p-surface) center/cover no-repeat;border:1px solid var(--p-border)}
.ac-thumb--none{background:var(--p-surface)}
.ac-tot{padding:5px 2px;display:flex;justify-content:space-between;font-size:13.5px;font-variant-numeric:tabular-nums}
.ac-tot:first-child{padding-top:10px}
.ac-tot--grand{font-weight:700;font-size:15px;border-top:1px solid var(--p-border);margin-top:4px;padding-top:9px}
.ac-tot--due{font-weight:700;color:color-mix(in srgb,var(--bad) 75%,var(--p-text))}
.ac-note{padding:12px 2px;border-bottom:1px solid var(--p-border);font-size:13.5px}
.ac-note-txt{white-space:pre-wrap}
.ac-note-m{font-size:11px;opacity:.5;margin-top:4px}
.ac-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:8px;margin-top:10px}
.ac-photos a{aspect-ratio:1;border-radius:var(--p-r);background:var(--p-surface) center/cover no-repeat;border:1px solid var(--p-border)}

/* ---- account forms: open sections, no box */
.ac-form-block{border-top:1px solid var(--p-border);padding:18px 2px 4px;margin-bottom:28px}

/* ---- print: just the record */
@media print{
  .ac-nav,.ac-dact,.ac-back,.ac-compose,.ac-legend,.ac-msg-wrap,#conversation,.ac-top,.p-nav-wrap,footer,.ac-intake-footer{display:none!important}
  .ac-body{padding:0!important;max-width:none!important}
  body{background:#fff!important;color:#111!important}
}
</style>
