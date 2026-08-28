{{-- MARKER-IMPORT1 — shared importer styling, all off the --ia-* theme vars --}}
<style>
.imp{width:100%;border-collapse:collapse;font-size:12.5px}
.imp th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;
        color:var(--ia-text-dim);font-weight:600;padding:9px 14px;border-bottom:.5px solid var(--ia-border)}
.imp td{padding:9px 14px;border-bottom:.5px solid rgba(255,255,255,.06);vertical-align:middle}
.imp tr:last-child td{border-bottom:0}
.imp-scroll{max-height:460px;overflow-y:auto}
.imp-sample{color:var(--ia-text-dim);font-size:11.5px;font-family:ui-monospace,monospace;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;display:block}
.imp-sel,.imp-dir{font-size:12.5px;padding:6px 9px;border-radius:6px;border:.5px solid var(--ia-border);
        background:var(--ia-input-bg);color:var(--ia-text);font-family:inherit;width:100%}
.imp-drop{border:1.5px dashed var(--ia-border-strong);border-radius:var(--ia-r-lg);padding:30px 20px;
          text-align:center;background:rgba(255,255,255,.02);font-size:12.5px;color:var(--ia-text-dim)}
.imp-two{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
@media(max-width:760px){.imp-two{grid-template-columns:1fr}}
.imp-radio{display:flex;gap:9px;align-items:flex-start;padding:8px 0;cursor:pointer}
.imp-radio input{margin-top:3px;accent-color:var(--ia-accent)}
.imp-radio b{font-weight:600;font-size:13px;display:block}
.imp-radio span span{font-size:11.5px;color:var(--ia-text-dim);display:block;margin-top:1px;line-height:1.45}
.imp-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
.imp-tile{background:var(--ia-surface);border-radius:var(--ia-r-lg);padding:14px 16px;
          box-shadow:inset 0 0 0 .5px var(--ia-border)}
.imp-tile .k{font-size:11px;color:var(--ia-text-dim)}
.imp-tile .v{font-size:23px;font-weight:700;margin-top:2px;line-height:1}
.imp-tile .v.ok{color:#7FD98F}.imp-tile .v.acc{color:var(--ia-accent)}
.imp-tile .v.bad{color:#F09595}.imp-tile .v.dim{color:var(--ia-text-dim)}
.imp-foot{display:flex;justify-content:space-between;gap:10px;margin-top:18px}
.imp-hint{font-size:11.5px;color:var(--ia-text-dim);line-height:1.55}
.imp-empty{padding:28px;text-align:center;font-size:13px;color:var(--ia-text-dim)}
.imp-err{font-size:11.5px;color:#F09595;margin-top:3px}
.imp-changes{font-size:11px;color:var(--ia-text-dim);margin-left:6px}
.chip{display:inline-flex;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
      padding:3px 8px;border-radius:100px;white-space:nowrap}
.chip--create,.chip--done{background:rgba(127,217,143,.13);color:#7FD98F;border:.5px solid rgba(127,217,143,.3)}
.chip--update,.chip--previewed{background:rgba(190,242,100,.10);color:var(--ia-accent);border:.5px solid rgba(190,242,100,.3)}
.chip--error,.chip--failed{background:rgba(240,149,149,.11);color:#F09595;border:.5px solid rgba(240,149,149,.3)}
.chip--unchanged,.chip--skipped,.chip--unmatched,.chip--draft,.chip--running{
      background:rgba(255,255,255,.05);color:var(--ia-text-dim);border:.5px solid rgba(255,255,255,.1)}
.mono{font-family:ui-monospace,monospace;font-size:12px}

/* MARKER-IMPORT3 — hub hierarchy, type cards, richer history rows */
.imp-sec{margin-bottom:30px}
.imp-sec-h{display:flex;align-items:baseline;gap:10px;margin-bottom:4px}
.imp-sec-n{font-size:10px;font-weight:800;letter-spacing:.09em;color:var(--ia-accent);
  border:.5px solid var(--ia-accent);border-radius:100px;padding:2px 8px}
.imp-sec-t{font-size:15px;font-weight:650;letter-spacing:-.01em}
.imp-sec-s{font-size:12.5px;color:var(--ia-text-dim);margin:0 0 14px}
.imp-types{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:760px){.imp-types{grid-template-columns:1fr}}
/* MARKER-IMPORT-CTA — the card is the action, so it has to look like one. */
.imp-type{background:var(--ia-surface);border-radius:var(--ia-r-lg);
  box-shadow:inset 0 0 0 .5px var(--ia-border);padding:20px 24px;
  display:flex;flex-direction:column;transition:background .14s,box-shadow .14s}
.imp-type:hover{background:var(--ia-surface-2);box-shadow:inset 0 0 0 .5px var(--ia-border-strong)}
.imp-type:focus-within{box-shadow:inset 0 0 0 1px var(--ia-accent)}
.imp-type-hit{display:flex;flex-direction:column;gap:9px;text-decoration:none;color:var(--ia-text);
  border-radius:var(--ia-r,8px);outline:none}
.imp-type-hit:focus-visible{outline:2px solid var(--ia-accent);outline-offset:3px}
.imp-type-go{display:flex;align-items:center;gap:10px;margin-top:14px;padding-top:14px;
  border-top:.5px solid var(--ia-border)}
.imp-type-go .ia-btn{text-decoration:none}
.imp-type-go .imp-type-alt{font-size:11.5px;color:var(--ia-text-dim);text-decoration:none;
  border-bottom:.5px solid transparent}
.imp-type-go .imp-type-alt:hover{color:var(--ia-text);border-bottom-color:currentColor}
.imp-type-go .imp-type-alt:focus-visible{outline:2px solid var(--ia-accent);outline-offset:2px;border-radius:3px}
.imp-type-arrow{margin-left:auto;font-size:13px;color:var(--ia-text-dim);
  opacity:0;transform:translateX(-3px);transition:opacity .14s,transform .14s}
.imp-type:hover .imp-type-arrow{opacity:1;transform:none}
.imp-type-top{display:flex;align-items:center;gap:10px}
.imp-type-ico{width:30px;height:30px;border-radius:8px;background:var(--ia-input-bg);
  display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.imp-type h4{font-size:14.5px;font-weight:650}
.imp-type-count{margin-left:auto;font-size:11.5px;color:var(--ia-text-dim)}
.imp-type-fields{font-size:12px;color:var(--ia-text-dim);line-height:1.6}
.imp-type-meta{display:flex;gap:8px;flex-wrap:wrap}
.imp-tag{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:100px;
  background:var(--ia-input-bg);color:var(--ia-text-dim)}
.imp-tag.key{color:var(--ia-accent)}
.imp-type-links{display:flex;gap:12px;margin-top:10px;font-size:11.5px}
.imp-type-links a{color:var(--ia-accent);text-decoration:none;border-bottom:.5px solid currentColor}
.imp-when{font-weight:600}
.imp-when span{display:block;font-size:10.5px;font-weight:400;color:var(--ia-text-dim)}
.imp-file{font-family:ui-monospace,monospace;font-size:11.5px}
.imp-file span{display:block;font-family:inherit;font-size:10.5px;color:var(--ia-text-dim);margin-top:1px}
.imp-nums{display:flex;gap:12px;font-variant-numeric:tabular-nums}
.imp-num b{font-size:13.5px}
.imp-num i{font-style:normal;font-size:10px;color:var(--ia-text-dim);display:block;
  letter-spacing:.03em;text-transform:uppercase}
.imp-num.ok b{color:#7FD98F}.imp-num.acc b{color:var(--ia-accent)}.imp-num.bad b{color:#F09595}
.imp-acts{display:flex;gap:6px;justify-content:flex-end;align-items:center}
.imp-acts form{display:inline}
.imp-empty b{display:block;color:var(--ia-text);font-size:14px;font-weight:600;margin-bottom:4px}
.imp-drop.has{border-style:solid;border-color:var(--ia-accent);background:rgba(190,242,100,.05);
  text-align:left;display:flex;align-items:center;gap:14px;padding:18px 20px}
.imp-drop.has[hidden]{display:none}
.imp-drop h4{font-size:14px;font-weight:600;margin-bottom:3px;color:var(--ia-text)}
.imp-file-ico{width:34px;height:34px;border-radius:8px;background:var(--ia-input-bg);display:flex;
  align-items:center;justify-content:center;flex:0 0 auto;font-size:11px;font-weight:700}
details.imp-ref{border-top:.5px solid var(--ia-border);padding-top:12px;margin-top:14px}
details.imp-ref summary{font-size:12.5px;color:var(--ia-text-dim);cursor:pointer;list-style:none;
  display:flex;align-items:center;gap:7px}
details.imp-ref summary::-webkit-details-marker{display:none}
details.imp-ref summary::before{content:'\25B8';font-size:9px}
details.imp-ref[open] summary::before{content:'\25BE'}
.imp-ref-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));
  gap:5px 14px;margin-top:11px;font-size:12px;color:var(--ia-text-muted)}
.imp-ref-grid b{color:var(--ia-accent);font-weight:600}
.imp-ref-no{margin-top:12px;font-size:11.5px;color:var(--ia-text-dim);line-height:1.6;
  border-left:2px solid var(--ia-border);padding-left:11px}
.chip--reversed{background:rgba(255,255,255,.05);color:var(--ia-text-dim);border:.5px solid rgba(255,255,255,.12)}

/* MARKER-IMPORT-MERGE — merge review: one decision per field, samples under it */
.imp-fg{padding:16px 18px;border-bottom:.5px solid var(--ia-border)}
.imp-fg:last-child{border-bottom:0}
.imp-fg-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.imp-fg-name{font-weight:650;font-size:13.5px}
.imp-fg-count{font-size:12px;color:var(--ia-accent)}
.imp-seg{display:inline-flex;background:var(--ia-input-bg);border-radius:9px;padding:3px;margin-left:auto;
  box-shadow:inset 0 0 0 .5px var(--ia-border)}
.imp-seg label{padding:5px 11px;font-size:11.5px;font-weight:600;border-radius:6px;cursor:pointer;
  color:var(--ia-text-dim);white-space:nowrap}
.imp-seg input{position:absolute;opacity:0;width:0;height:0}
.imp-seg label:has(input:checked){background:var(--ia-accent);color:#0a0a0a}
.imp-seg label:focus-within{outline:2px solid var(--ia-accent);outline-offset:2px}
.imp-fg-sample{margin-top:11px;border-radius:9px;overflow:hidden;background:var(--ia-input-bg);
  box-shadow:inset 0 0 0 .5px var(--ia-border)}
.imp-fg-sample .imp td,.imp-fg-sample .imp th{padding:7px 12px}
.imp-was{color:var(--ia-text-dim);text-decoration:line-through;text-decoration-color:rgba(255,255,255,.25)}
.imp-now{color:var(--ia-accent)}
.imp-kept{color:var(--ia-text)}
.imp-more{margin-top:9px;font-size:11.5px;color:var(--ia-text-dim)}
.imp-more a{color:var(--ia-accent);text-decoration:none;border-bottom:.5px solid currentColor}
.imp-rowseg{display:inline-flex;gap:5px}
.imp-rowseg label{padding:3px 9px;font-size:11px;border-radius:6px;cursor:pointer;
  color:var(--ia-text-dim);box-shadow:inset 0 0 0 .5px var(--ia-border)}
.imp-rowseg input{position:absolute;opacity:0;width:0;height:0}
.imp-rowseg label:has(input:checked){background:var(--ia-accent);color:#0a0a0a;font-weight:650}
.imp-rowseg label:focus-within{outline:2px solid var(--ia-accent);outline-offset:2px}
.imp-legend{font-size:11.5px;color:var(--ia-text-dim);line-height:1.6;padding:12px 18px;
  border-top:.5px solid var(--ia-border)}
.imp-pager{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:14px;font-size:12px}
.imp-pager a{color:var(--ia-accent);text-decoration:none}
.imp-pager span{color:var(--ia-text-dim)}
</style>
