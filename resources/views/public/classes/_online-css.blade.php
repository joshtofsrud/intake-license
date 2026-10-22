{{-- MARKER-CLASSES-P3: shared styles for buying classes online (passes, checkout, receipt, class page) --}}
<style>
.ps-tabs{display:flex;gap:18px;border-bottom:1px solid var(--p-border);margin-bottom:22px;font-size:14px;overflow-x:auto}
.ps-tab{padding:0 0 10px;opacity:.5;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.ps-tab:hover{opacity:.8}
.ps-tab.on{opacity:1;font-weight:600;border-color:var(--p-text)}
.ps-intro{display:flex;align-items:center;gap:14px;padding:18px 20px;background:var(--p-accent);color:var(--p-accent-text);border-radius:var(--p-r-lg);margin-bottom:26px}
.ps-intro-k{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.65}
.ps-intro-n{font-size:19px;font-weight:700;font-family:var(--p-font-heading);line-height:1.25;margin-top:1px}
.ps-intro-s{font-size:13px;opacity:.75;margin-top:2px}
.ps-intro-p{margin-left:auto;text-align:right;white-space:nowrap}
.ps-intro-p b{display:block;font-size:22px;font-weight:700}
.ps-intro-p span{font-size:12px;opacity:.65}
.ps-group{margin-bottom:26px}
.ps-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin-bottom:10px}
.ps-line{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--p-border)}
.ps-label + .ps-line{border-top:1px solid var(--p-border)}
.ps-line-n{font-size:15px;font-weight:600}
.ps-line-s{font-size:13px;opacity:.55;margin-top:1px}
.ps-line-r{margin-left:auto;display:flex;align-items:center;gap:12px;white-space:nowrap}
.ps-line-p{font-size:15px;font-weight:600;text-align:right;line-height:1.25}
.ps-line-p small{display:block;font-size:11.5px;font-weight:400;opacity:.5}
.ps-buy{padding:7px 14px;border-radius:var(--p-r);border:1.5px solid var(--p-text);font-size:13px;font-weight:600;color:var(--p-text)}
.ps-have{font-size:12px;font-weight:500;padding:2px 9px;border-radius:20px;background:#EAF3DE;color:#3B6D11}
.ps-note{font-size:12.5px;opacity:.5;margin-top:8px}
.ps-dropin{font-size:13px;padding:12px 15px;background:var(--p-surface);border-radius:var(--p-r);display:flex;justify-content:space-between;gap:10px}
.ps-dropin span{opacity:.6}
.ps-promo{display:flex;align-items:center;gap:12px;padding:12px 15px;border:1.5px solid var(--p-border);border-radius:var(--p-r-lg);margin-bottom:18px;font-size:13.5px}
.ps-promo b{font-weight:600}
.ps-promo span{margin-left:auto;font-weight:600;white-space:nowrap}
.ps-empty{padding:36px 10px;text-align:center;opacity:.5;font-size:14px}
.co-sum{background:var(--p-surface);border-radius:var(--p-r-lg);padding:16px 18px;margin-bottom:20px}
.co-sum-n{font-weight:600;font-size:15px}
.co-sum-s{font-size:13px;opacity:.55;margin-bottom:8px}
.co-sum-row{display:flex;justify-content:space-between;gap:12px;font-size:14px;padding:3px 0}
.co-sum-row.dim{opacity:.6}
.co-sum-row.t{font-weight:700;font-size:16px;border-top:1px solid var(--p-border);margin-top:8px;padding-top:10px}
.co-pe{border:1.5px solid var(--p-border);border-radius:var(--p-r-lg);padding:14px;margin-bottom:12px;min-height:60px}
.co-terms{font-size:12.5px;opacity:.7;line-height:1.55;margin:4px 0 14px;padding:12px 14px;border-left:3px solid var(--p-border)}
.co-check{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;margin-bottom:16px;cursor:pointer}
.co-check input{margin-top:4px;width:16px;height:16px;flex-shrink:0}
.co-fine{font-size:12px;opacity:.5;text-align:center;margin-top:10px}
.co-err{display:none}
.ok-mark{width:52px;height:52px;border-radius:50%;background:var(--p-accent);color:var(--p-accent-text);display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.ok-list{border-top:1px solid var(--p-border);margin:18px 0}
.ok-list div{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--p-border);font-size:14px}
.ok-list span{opacity:.55}
.ok-list b{text-align:right}
.ac-link{color:inherit;text-decoration:underline;text-underline-offset:2px}
.legend{font-size:12px;opacity:.55;margin-top:22px;line-height:1.55;padding-top:12px;border-top:1px dashed var(--p-border)}
@media (max-width:520px){
  .ps-intro{flex-wrap:wrap}
  .ps-intro-p{margin-left:0;text-align:left}
  .ps-line{flex-wrap:wrap;gap:8px}
  .ps-line-r{margin-left:0;width:100%;justify-content:space-between}
  .ps-line-p{text-align:left}
}
</style>
