{{-- Shared look for master-admin pages built on Filament's colour variables (Search defaults, Site templates). --}}
<style>
  .lcm{--m-card:#fff;--m-ring:rgba(9,9,11,.08);--m-tx:rgb(var(--gray-950));--m-mu:rgb(var(--gray-500));--m-row:rgba(9,9,11,.06);--m-head:rgb(var(--gray-50));--m-in:#fff;--m-inring:rgba(9,9,11,.18);display:flex;flex-direction:column;gap:24px}
  .dark .lcm{--m-card:rgb(var(--gray-900));--m-ring:rgba(255,255,255,.1);--m-tx:#fff;--m-mu:rgb(var(--gray-400));--m-row:rgba(255,255,255,.06);--m-head:rgba(255,255,255,.04);--m-in:rgba(255,255,255,.05);--m-inring:rgba(255,255,255,.2)}
  .lcm-tabs{display:flex;gap:4px;overflow-x:auto;padding:4px;border-radius:12px;background:var(--m-card);box-shadow:0 0 0 1px var(--m-ring);width:max-content;max-width:100%}
  .lcm-tabs button,.lcm-tabs a{border:0;background:transparent;color:var(--m-mu);font-weight:500;font-size:14px;padding:7px 12px;border-radius:8px;cursor:pointer;white-space:nowrap}
  .lcm-tabs button:hover,.lcm-tabs a:hover{background:var(--m-row)}
  .lcm-tabs .on{background:rgba(var(--primary-500),.12);color:rgb(var(--primary-600))}.dark .lcm-tabs .on{color:rgb(var(--primary-400))}
  .lcm-bar2{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
  .lcm-sec{background:var(--m-card);border-radius:12px;box-shadow:0 0 0 1px var(--m-ring),0 1px 2px rgba(0,0,0,.05)}
  .lcm-sec>header{padding:14px 22px;border-bottom:1px solid var(--m-row)}.lcm-sec>header h3{font-weight:600;font-size:16px;line-height:24px;color:var(--m-tx)}.lcm-sec>header p{font-size:13.5px;color:var(--m-mu);margin-top:2px}
  .lcm-sec .b{padding:6px 22px}.lcm-sec.p{padding:16px 22px}
  .lcm-row{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:14px 0;border-bottom:1px solid var(--m-row)}.lcm-row:last-child{border:0}
  .lcm-row .t{font-size:14px;font-weight:500;color:var(--m-tx)}.lcm-row .d{font-size:13px;color:var(--m-mu);margin-top:2px;max-width:62ch}
  .lcm-ctl{display:flex;gap:12px;align-items:center;flex:none;font-size:13.5px;color:var(--m-tx)}
  .lcm-tbl{overflow-x:auto}.lcm-tbl table{width:100%;border-collapse:collapse;font-size:14px;color:var(--m-tx)}
  .lcm-tbl th{text-align:left;font-weight:600;font-size:13.5px;padding:12px;background:var(--m-head);white-space:nowrap}.lcm-tbl td{padding:10px 12px;border-top:1px solid var(--m-row);vertical-align:middle}
  .lcm-tbl th:first-child,.lcm-tbl td:first-child{padding-left:22px}.lcm-tbl th:last-child,.lcm-tbl td:last-child{padding-right:22px}
  .lcm-tbl .n{text-align:right;white-space:nowrap}.lcm .mu{color:var(--m-mu)}.lcm .sm{font-size:13px}
  .lcm-in,.lcm-sel,.lcm-ta{background:var(--m-in);color:var(--m-tx);border:0;border-radius:8px;box-shadow:inset 0 0 0 1px var(--m-inring);padding:6px 10px;font-size:14px;outline:none;max-width:100%}
  .lcm-sel{padding-right:30px;appearance:none;-webkit-appearance:none;background-repeat:no-repeat;background-position:right .45rem center;background-size:1.1em}.lcm-sel option{background:rgb(var(--gray-900));color:#fff}
  .lcm-in:focus,.lcm-sel:focus,.lcm-ta:focus{box-shadow:inset 0 0 0 2px rgb(var(--primary-500))}
  .lcm-ta,.lcm-modal .lcm-in,.lcm-modal .lcm-sel{width:100%;display:block}
  .lcm-tg{position:relative;display:inline-block;width:44px;height:24px;flex:none}.lcm-tg input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;z-index:1}.lcm-tg i{position:absolute;inset:0;border-radius:99px;background:rgb(var(--gray-300));transition:.15s}.dark .lcm-tg i{background:rgb(var(--gray-700))}.lcm-tg i:before{content:'';position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.3);transition:.15s}.lcm-tg input:checked+i{background:rgb(var(--primary-600))}.lcm-tg input:checked+i:before{transform:translateX(20px)}.lcm-tg input:disabled+i{opacity:.4}
  .lcm-btn{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:14px;border-radius:8px;padding:6px 12px;cursor:pointer;border:0;background:rgb(var(--primary-600));color:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2)}.lcm-btn:hover{background:rgb(var(--primary-500))}
  .lcm-btn.g{background:var(--m-card);color:var(--m-tx);box-shadow:0 0 0 1px var(--m-inring)}.lcm-btn.g:hover{background:var(--m-row)}
  .lcm-btn.r{background:transparent;color:rgb(var(--danger-500));box-shadow:0 0 0 1px rgba(var(--danger-500),.5)}.lcm-btn.r:hover{background:rgba(var(--danger-500),.1)}
  .lcm-btn.s{padding:3px 9px;font-size:13px}.lcm-btn:disabled{opacity:.45;cursor:default}
  .lcm-badge{display:inline-flex;align-items:center;font-weight:500;font-size:12px;padding:2px 8px;border-radius:6px;white-space:nowrap}
  .b-ok{background:rgba(var(--success-500),.12);color:rgb(var(--success-600));box-shadow:inset 0 0 0 1px rgba(var(--success-600),.25)}.dark .b-ok{color:rgb(var(--success-400))}
  .b-bad{background:rgba(var(--danger-500),.12);color:rgb(var(--danger-600));box-shadow:inset 0 0 0 1px rgba(var(--danger-600),.25)}.dark .b-bad{color:rgb(var(--danger-400))}
  .b-warn{background:rgba(var(--warning-500),.12);color:rgb(var(--warning-700));box-shadow:inset 0 0 0 1px rgba(var(--warning-600),.3)}.dark .b-warn{color:rgb(var(--warning-400))}
  .b-pri{background:rgba(var(--primary-500),.12);color:rgb(var(--primary-600));box-shadow:inset 0 0 0 1px rgba(var(--primary-600),.25)}.dark .b-pri{color:rgb(var(--primary-400))}
  .b-gray{background:rgba(var(--gray-500),.1);color:rgb(var(--gray-600));box-shadow:inset 0 0 0 1px rgba(var(--gray-500),.25)}.dark .b-gray{color:rgb(var(--gray-300))}
  .lcm-bar{height:6px;border-radius:99px;background:var(--m-row);overflow:hidden;min-width:90px}.lcm-bar i{display:block;height:100%;background:rgb(var(--primary-500))}
  .lcm-note{border-radius:12px;padding:12px 18px;font-size:13.5px;background:rgba(var(--primary-500),.07);box-shadow:inset 0 0 0 1px rgba(var(--primary-500),.25);color:var(--m-tx)}.lcm-note b{color:rgb(var(--primary-600))}.dark .lcm-note b{color:rgb(var(--primary-400))}.lcm-note div{margin-top:5px;color:var(--m-mu)}
  .lcm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px}.lcm-stat{padding:14px 18px}.lcm-stat .l{font-size:13px;color:var(--m-mu)}.lcm-stat .v{font-size:28px;font-weight:700;color:var(--m-tx);line-height:1.2}
  .lcm-save{display:flex;gap:12px;align-items:center;padding:14px 22px;border-top:1px solid var(--m-row)}
  .lcm-two{display:grid;grid-template-columns:1fr 1fr;gap:24px}
  .lcm-modal label{display:block;font-weight:500;font-size:14px;margin:14px 0 5px}
  .lcm-err{color:rgb(var(--danger-500));font-size:13px;margin-top:6px}
  @media(max-width:900px){.lcm-two{grid-template-columns:1fr}.lcm-row{flex-direction:column;align-items:flex-start}}
</style>
