{{-- shared portal styles, pushed by each portal view --}}
<style>
/* list primitives. These lived in the old single-page
   portal.blade.php, which v2 orphaned; every section uses them, so they
   belong here. Values carried over unchanged from that view. */
.ac-section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.4;margin-bottom:10px}
.ac-list{border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden;margin-bottom:20px}
.ac-list-row{padding:12px 15px;border-bottom:1px solid var(--p-border);display:flex;align-items:center;justify-content:space-between;font-size:14px;gap:12px;color:inherit;text-decoration:none}
.ac-list-row:last-child{border-bottom:none}
a.ac-list-row:hover{background:var(--p-surface)}
.ac-list-name{font-weight:500}
.ac-list-meta{font-size:12px;opacity:.5;margin-top:2px}
.ac-list-right{text-align:right;flex-shrink:0;margin-left:12px}
.ac-empty{padding:28px;text-align:center;font-size:14px;opacity:.35}
.ac-pill{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500;white-space:nowrap}
.ac-pill--registered,.ac-pill--checked_in,.ac-pill--confirmed,.ac-pill--active,.ac-pill--paid{background:#EAF3DE;color:#3B6D11}
.ac-pill--pending{background:#E6F1FB;color:#185FA5}
.ac-pill--waitlisted,.ac-pill--in_progress,.ac-pill--due{background:#FAEEDA;color:#633806}
.ac-pill--no_show,.ac-pill--cancelled,.ac-pill--completed,.ac-pill--returned{background:var(--p-surface);color:rgba(0,0,0,.4)}
.ac-pill--refunded{background:#FCEBEB;color:#A32D2D}

.ac-nav{display:flex;gap:2px;border-bottom:1px solid var(--p-border);margin:-6px 0 26px;overflow-x:auto}
.ac-nav a{padding:9px 13px;font-size:13.5px;opacity:.45;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.ac-nav a:hover{opacity:.75}
.ac-nav a.on{opacity:1;border-bottom-color:var(--p-accent);font-weight:600}
.ac-hero{background:var(--p-accent);color:var(--p-accent-text);border-radius:var(--p-r-lg);padding:20px;margin-bottom:12px}
.ac-hero-k{font-size:11px;text-transform:uppercase;letter-spacing:.07em;opacity:.65;margin-bottom:4px}
.ac-hero-t{font-size:19px;font-weight:700}
.ac-hero-m{font-size:13.5px;opacity:.8;margin-top:2px}
.ac-hero-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.ac-hero-actions a{font-size:12.5px;font-weight:600;padding:7px 13px;border-radius:20px;background:rgba(0,0,0,.14)}
.ac-banner{display:flex;justify-content:space-between;align-items:center;gap:12px;border-radius:var(--p-r-lg);padding:13px 16px;margin-bottom:12px;font-size:13.5px;border:1px solid}
.ac-banner--due{background:#FAEEDA;border-color:rgba(99,56,6,.25);color:#633806}
.ac-banner--overdue{background:#FCEBEB;border-color:rgba(163,45,45,.3);color:#A32D2D}
.ac-banner--soon{background:#EAF3DE;border-color:rgba(59,109,17,.25);color:#3B6D11}
.ac-banner b{font-weight:700}
.ac-banner a{font-weight:600;white-space:nowrap;border-bottom:1px solid currentColor}
.ac-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0 22px}
.ac-chip-card{border:1px solid var(--p-border);border-radius:var(--p-r);padding:12px 14px}
.ac-chip-k{font-size:11px;opacity:.5}
.ac-chip-v{font-size:16px;font-weight:700;margin-top:1px}
.ac-chip-s{font-size:11px;opacity:.45;margin-top:1px}
.ac-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-top:6px}
.ac-quick a{border:1.5px solid var(--p-border);border-radius:var(--p-r);padding:13px 10px;text-align:center;font-weight:600;font-size:13.5px}
.ac-msg-wrap{border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden;margin-bottom:14px}
.ac-msgs{padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--p-surface);max-height:480px;overflow-y:auto}
.ac-msg{max-width:78%;padding:9px 12px;border-radius:12px;font-size:13.5px;line-height:1.45;background:var(--p-bg);border:1px solid var(--p-border);word-break:break-word;white-space:pre-wrap}
.ac-msg.me{align-self:flex-end;background:var(--p-accent);color:var(--p-accent-text);border-color:transparent;border-bottom-right-radius:4px}
.ac-msg.shop{align-self:flex-start;border-bottom-left-radius:4px}
.ac-msg.txn{align-self:stretch;max-width:none;background:transparent;border-style:dashed;font-size:12px;opacity:.65;white-space:normal}
.ac-msg-t{font-size:10px;opacity:.5;margin-top:4px;white-space:normal}
.ac-chan{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.07em;padding:1px 5px;border-radius:4px;background:rgba(0,0,0,.1);margin-right:5px;vertical-align:1px}
.ac-compose{display:flex;gap:8px;padding:12px;border-top:1px solid var(--p-border)}
.ac-compose textarea{flex:1;padding:10px 13px;border:1.5px solid var(--p-border);border-radius:14px;font-family:inherit;font-size:14px;background:transparent;color:inherit;resize:none}
/* MARKER-PORTAL-DETAIL: work order + purchase detail pages */
a.ac-list-row .ac-go{opacity:.3;font-size:16px;margin-left:8px}
.ac-back{display:inline-block;font-size:13px;opacity:.55;margin-bottom:12px}
.ac-back:hover{opacity:.9}
.ac-dhead{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:16px}
.ac-dtitle{font-size:21px;font-weight:700;font-family:var(--p-font-heading);line-height:1.25}
.ac-dsub{font-size:13px;opacity:.5;margin-top:3px}
.ac-dact{display:flex;gap:6px;flex-shrink:0}
.ac-dact a{font-size:12.5px;font-weight:600;padding:6px 12px;border-radius:20px;border:1.5px solid var(--p-border);white-space:nowrap}
.ac-dact a:hover{border-color:var(--p-text)}
.ac-steps{display:flex;margin:4px 0 18px;border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden}
.ac-step{flex:1;padding:10px 8px;text-align:center;font-size:12px;border-right:1px solid var(--p-border);opacity:.4}
.ac-step:last-child{border-right:none}
.ac-step.done{opacity:.75}
.ac-step.now{opacity:1;background:var(--p-accent);color:var(--p-accent-text);font-weight:700}
.ac-step small{display:block;font-size:10.5px;font-weight:400;opacity:.8;margin-top:1px}
.ac-kv{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;font-size:13.5px;margin-bottom:20px}
.ac-kv dt{opacity:.5}
.ac-kv dd{margin:0}
.ac-grp{padding:9px 15px;font-size:12px;font-weight:700;background:var(--p-surface);border-bottom:1px solid var(--p-border)}
.ac-grp span{font-weight:400;opacity:.55;margin-left:6px}
.ac-ans{padding:9px 15px;border-bottom:1px solid var(--p-border);font-size:12.5px}
.ac-ans b{font-weight:600;opacity:.6;margin-right:6px}
.ac-line{display:flex;justify-content:space-between;gap:12px;padding:9px 15px;border-bottom:1px solid var(--p-border);font-size:13.5px}
.ac-line:last-child{border-bottom:none}
.ac-line-m{font-size:11.5px;opacity:.5;margin-top:1px}
.ac-line-r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.ac-tot{padding:6px 15px;display:flex;justify-content:space-between;font-size:13.5px;font-variant-numeric:tabular-nums}
.ac-tot:first-child{padding-top:11px}
.ac-tot:last-child{padding-bottom:11px}
.ac-tot--grand{font-weight:700;font-size:15px;border-top:1px solid var(--p-border);margin-top:4px;padding-top:9px}
.ac-tot--due{font-weight:700;color:#A32D2D}
.ac-note{padding:11px 15px;border-bottom:1px solid var(--p-border);font-size:13.5px;white-space:pre-wrap}
.ac-note:last-child{border-bottom:none}
.ac-note-m{font-size:11px;opacity:.45;margin-top:3px;white-space:normal}
.ac-about{display:inline-block;font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:10px;background:rgba(0,0,0,.12);margin-bottom:4px;color:inherit}
.ac-legend{font-size:12px;opacity:.45;margin:-6px 0 20px}
.ac-pager{display:flex;justify-content:space-between;margin:-10px 0 20px;font-size:13px}
.ac-pager a{font-weight:600}
.ac-compose button{border:0;background:var(--p-accent);color:var(--p-accent-text);border-radius:50%;width:42px;height:42px;font-size:16px;font-weight:700;flex:0 0 auto;align-self:flex-end}
</style>
