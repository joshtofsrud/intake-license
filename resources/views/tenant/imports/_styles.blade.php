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
</style>
