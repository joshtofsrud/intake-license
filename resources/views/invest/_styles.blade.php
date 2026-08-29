{{-- MARKER-INVEST-V2 — one stylesheet for the gated surfaces. The palette is
     the marketing site's; change it there and mirror here. 1080 like every
     other Intake surface, with the reading and entry columns held to a
     comfortable measure internally rather than by narrowing the page. --}}
<style>
:root{
  --bg:#0c0c0c; --panel:#141414; --panel2:#1a1a1a;
  --line:rgba(255,255,255,.08); --line2:rgba(255,255,255,.14);
  --text:#f0f0f0; --body:rgba(255,255,255,.45); --dim:rgba(255,255,255,.28);
  --lime:#BEF264; --lime-soft:rgba(190,242,100,.09); --lime-line:rgba(190,242,100,.34);
  --amber:#FBBF24; --red:#FCA5A5; --max:1080px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,sans-serif;
  font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased}
.wrap{max-width:var(--max);margin:0 auto;padding:0 28px}
nav{border-bottom:1px solid var(--line)}
nav .wrap{display:flex;align-items:center;gap:18px;height:66px}
.brand{display:flex;align-items:center;gap:10px;font-size:19px;font-weight:700;letter-spacing:-.5px;
  color:var(--text);text-decoration:none}
.brand img{width:26px;height:26px;border-radius:6px;display:block}
.who{margin-left:auto;font-size:12.5px;color:var(--dim)}
.who b{color:var(--text)}
.eyebrow{font-size:11px;font-weight:600;letter-spacing:2.6px;text-transform:uppercase;color:var(--lime)}
h1{font-size:clamp(27px,4vw,40px);font-weight:800;letter-spacing:-1.6px;line-height:1.08;margin:14px 0 0}
h2{font-size:20px;font-weight:750;letter-spacing:-.7px;margin:0}
h3{font-size:15px;font-weight:650}
p{color:var(--body);line-height:1.65}
b,strong{color:var(--text);font-weight:600}
.lede{font-size:16px;max-width:62ch;margin-top:14px}
section{padding:44px 0;border-top:1px solid var(--line)}
section.hero{border-top:0;padding:56px 0 40px}
.sub{font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--dim);
  padding-bottom:10px;border-bottom:1px solid var(--line);margin:0 0 20px}

.prog{background:var(--panel);border:1px solid var(--line);border-radius:13px;padding:22px;margin-top:26px}
.progtop{display:flex;align-items:baseline;gap:13px;flex-wrap:wrap}
.progtop .big{font-size:32px;font-weight:800;color:var(--lime);letter-spacing:-1.5px;line-height:1}
.progtop .of{font-size:11.5px;letter-spacing:1.5px;text-transform:uppercase;color:var(--dim);font-weight:600}
.bar{height:10px;border-radius:6px;background:var(--panel2);margin-top:16px;overflow:hidden;display:flex}
.bar i{display:block;height:100%}
.b1{background:var(--lime)}.b2{background:rgba(190,242,100,.32)}
.key{display:flex;gap:20px;margin-top:12px;flex-wrap:wrap;font-size:12px;color:var(--dim)}
.key span{display:flex;align-items:center;gap:6px}
.key i{width:10px;height:10px;border-radius:3px;display:block}
.k1{background:var(--lime)}.k2{background:rgba(190,242,100,.32)}.k3{background:var(--panel2)}

.docs{display:flex;gap:11px;flex-wrap:wrap}
.doc{flex:1;min-width:190px;background:var(--panel);border:1px solid var(--line);border-radius:11px;
  padding:15px 17px;text-decoration:none;display:block}
.doc:hover{border-color:var(--lime-line)}
.doc b{display:block;font-size:14.5px;color:var(--text)}
.doc span{font-size:11.5px;color:var(--dim)}

.steps{background:var(--panel);border:1px solid var(--line);border-radius:13px;padding:4px 22px}
.step{display:flex;gap:16px;padding:20px 0;border-bottom:1px solid var(--line)}
.step:last-child{border-bottom:0}
.stepn{flex:0 0 30px;height:30px;border-radius:8px;background:var(--lime-soft);border:1px solid var(--lime-line);
  color:var(--lime);font-weight:800;display:flex;align-items:center;justify-content:center;font-size:13px}
.step.done .stepn{background:var(--lime);color:#0a0a0a;border-color:var(--lime)}
.step.wait .stepn{background:none;border-color:var(--line2);color:var(--dim)}
.stepb{flex:1;min-width:0;max-width:64ch}
.stepb p{font-size:13.5px;margin-top:5px}
.pill{display:inline-block;font-size:9.5px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;
  padding:3px 8px;border-radius:99px;border:1px solid var(--line2);color:var(--dim);margin-left:7px}
.pill.on{color:var(--lime);border-color:var(--lime-line);background:var(--lime-soft)}
.pill.amber{color:var(--amber);border-color:rgba(251,191,36,.4);background:rgba(251,191,36,.07)}

label{display:block;font-size:10px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--dim);margin:13px 0 6px}
input,select{width:100%;max-width:300px;background:var(--bg);border:1px solid var(--line2);border-radius:8px;
  color:var(--text);font-family:inherit;font-size:15px;padding:10px 12px;outline:none}
input:focus,select:focus{border-color:var(--lime-line)}
.btn{display:inline-block;background:var(--lime);color:#0a0a0a;border:0;border-radius:8px;
  font-family:inherit;font-size:14px;font-weight:700;padding:10px 18px;cursor:pointer;margin-top:14px;
  text-decoration:none}
.btn.ghost{background:none;color:var(--text);border:1px solid var(--line2);font-weight:600}
.btn.dim{background:var(--panel2);color:var(--dim);cursor:not-allowed}
.wire{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:15px 17px;margin-top:14px;
  font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.95;color:var(--text)}
.wire span{color:var(--dim);display:inline-block;width:150px;font-family:Inter,sans-serif;font-size:10px;
  letter-spacing:1.3px;text-transform:uppercase;font-weight:600}
.legend{border-left:2px solid var(--lime-line);padding:9px 0 9px 15px;margin-top:20px;font-size:13px;
  color:var(--body);max-width:72ch}
.legend b{color:var(--lime)}
.fine{margin-top:22px;padding-top:14px;border-top:1px solid var(--line);font-size:12px;color:var(--dim);
  line-height:1.6;max-width:78ch}
.ok{border:1px solid var(--lime-line);background:var(--lime-soft);border-radius:10px;padding:14px 16px;
  margin-top:16px;font-size:14px;color:var(--text)}
.cerr{color:var(--red);font-size:13px;display:block;margin-top:6px}
footer{border-top:1px solid var(--line);padding:28px 0;font-size:12px;color:var(--dim)}
@media(max-width:640px){.docs .doc{min-width:100%}}
</style>
