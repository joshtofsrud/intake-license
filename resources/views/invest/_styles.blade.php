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
/* MARKER-INVEST-RULES — no full-bleed dividers. Separation comes from the
   space between sections; a rule across the whole viewport only slices the
   page up. The short rule under each .sub label is a different thing and
   stays — it belongs to the label, not to the page. */
section{padding:52px 0}
section.hero{padding:56px 0 44px}
section + section{padding-top:8px}
.sub{font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--dim);
  padding-bottom:10px;border-bottom:1px solid var(--line);margin:0 0 18px}
/* The label owns the gap to whatever follows it — the block below must not add
   a second margin, or the label drifts away from the thing it labels. */
.sub + *{margin-top:0}

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
/* MARKER-INVEST-NOCODE — the request card stands alone now. Held to a readable
   measure rather than stretched across the full width. */
/* MARKER-ASK-FULLWIDTH — full width, like every section above it. The inputs
   inside keep a sane measure; only the card stretches. */
.onecard{max-width:none}
.onecard .card{padding:26px 28px}
.onecard form{max-width:560px}
.onecard .card > p{max-width:64ch}
.onecard .fine{max-width:74ch}

/* MARKER-INVEST-RAIL — sticky anchor rail. Plain text, no pills; the dot marks
   a section that is OPEN. */
.rail{position:sticky;top:0;z-index:30;background:rgba(12,12,12,.94);backdrop-filter:blur(12px);
  border-bottom:1px solid var(--line)}
.rail .wrap{display:flex;align-items:center;gap:22px;padding-top:12px;padding-bottom:12px;
  overflow-x:auto;scrollbar-width:none}
.rail .wrap::-webkit-scrollbar{display:none}
.rail a{flex:0 0 auto;font-size:13px;font-weight:550;color:var(--body);text-decoration:none;
  white-space:nowrap;display:flex;align-items:center;gap:8px;transition:color .12s}
.rail a:hover,.rail a.open{color:var(--text)}
.rail a i{width:6px;height:6px;border-radius:50%;background:var(--line2);display:block;flex:0 0 6px;
  transition:background .12s}
.rail a.open i{background:var(--lime)}

details.sec{border-top:1px solid var(--line)}
details.sec:last-of-type{border-bottom:1px solid var(--line)}
details.sec > summary{list-style:none;cursor:pointer;padding:20px 0;display:flex;align-items:baseline;
  gap:12px;font-size:16px;font-weight:650;color:var(--text)}
details.sec > summary::-webkit-details-marker{display:none}
details.sec > summary .cap{font-size:13px;font-weight:400;color:var(--dim)}
details.sec > summary::after{content:"+";margin-left:auto;color:var(--lime);font-size:19px;
  line-height:1;font-weight:400}
details.sec[open] > summary::after{content:"\2013"}
/* MARKER-SECTION-HEADINGS — inside a panel the summary is the heading, so the
   section's own eyebrow would be the same words again. Scoped to details.sec:
   these partials also render standalone on the gated page, where the eyebrow
   is the only label present. */
details.sec .body > section > .wrap > .sub:first-child,
details.sec .body .sub:first-child{display:none}
details.sec .body h2{margin-top:0}

/* Three cards want three columns; the two-column default was built for the
   four-card bike section and left the third one orphaned. */
.ctx-cards.three{grid-template-columns:repeat(3,1fr)}
@media(max-width:860px){.ctx-cards.three{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.ctx-cards.three{grid-template-columns:1fr}}

details.sec .body{padding-bottom:34px}
details.sec .body section{padding:0}
details.sec .body section + section{padding-top:30px}
@media(max-width:640px){
  .rail .wrap{gap:16px}
  details.sec > summary{font-size:15px;padding:17px 0}
  details.sec > summary .cap{display:none}
}

footer{border-top:1px solid var(--line);padding:28px 0;font-size:12px;color:var(--dim)}
/* MARKER-INVEST-CONTEXT — cost stack and proof cards, shared by the gated
   page and the personal page. */
.stack{background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.srow{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr) 110px;gap:18px;padding:13px 18px;
  border-bottom:1px solid var(--line);font-size:14.5px;align-items:baseline}
.srow:last-child{border-bottom:0}
.srow b{color:var(--text);font-weight:550}
.srow .note{color:var(--dim);font-size:13px}
.srow .amt{text-align:right;font-variant-numeric:tabular-nums;color:var(--body);white-space:nowrap}
.srow.sum{border-top:1px solid var(--line2)}
.srow.sum b,.srow.sum .amt{color:var(--text);font-weight:700}
.srow.tot{background:var(--panel2);border-top:1px solid var(--line2)}
.srow.tot b,.srow.tot .amt{color:var(--lime);font-weight:700}
/* MARKER-INVEST-CAPABILITY — nine small groups, three across. Deliberately
   lighter than the proof cards above them: this is coverage, not argument. */
.cap-core{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:22px}
.cap-grp{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px}
.cap-grp h3{color:var(--lime);font-size:12px;letter-spacing:1.5px;text-transform:uppercase;font-weight:700}
.cap-grp ul{list-style:none;margin-top:11px}
.cap-grp li{font-size:13.5px;color:var(--body);padding:4px 0 4px 15px;position:relative;line-height:1.45}
.cap-grp li::before{content:"";position:absolute;left:0;top:12px;width:5px;height:5px;border-radius:50%;
  background:var(--line2)}
@media(max-width:900px){.cap-core{grid-template-columns:1fr 1fr}}
/* MARKER-CAPABILITY-MOBILE — stays at two columns on a phone. One column meant
   nine full-width cards, which is most of a minute of scrolling for something
   that is meant to be a glance. Type and padding tighten to suit the narrower
   card; no group and no item is dropped. */
@media(max-width:600px){
  .cap-core{grid-template-columns:1fr 1fr;gap:9px;margin-top:18px}
  .cap-grp{padding:13px 12px;border-radius:10px}
  .cap-grp h3{font-size:10.5px;letter-spacing:1.2px}
  .cap-grp ul{margin-top:8px}
  .cap-grp li{font-size:11.5px;line-height:1.38;padding:2.5px 0 2.5px 11px}
  .cap-grp li::before{top:9px;width:4px;height:4px}
}
/* Below about 380 two columns would be too tight, so one column is right there
   — but with the same compact type rather than the full-size version. */
@media(max-width:380px){
  .cap-core{grid-template-columns:1fr}
}

.ctx-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:22px}
.ctx-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px}
.ctx-card .n{font-size:28px;font-weight:800;color:var(--lime);letter-spacing:-1.2px;line-height:1}
.ctx-card .k{font-size:10.5px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;
  color:var(--dim);margin-top:8px}
.ctx-card p{font-size:13.5px;margin-top:9px}
@media(max-width:760px){.ctx-cards{grid-template-columns:1fr}}
/* MARKER-STACK-MOBILE — the detail column moves to a second line rather than
   being hidden. Hiding it left rows reading "Retail 5", which says nothing. */
@media(max-width:700px){
  .srow{grid-template-columns:minmax(0,1fr) auto;gap:4px 12px;padding:11px 14px}
  .srow b{grid-column:1;grid-row:1}
  .srow .amt{grid-column:2;grid-row:1;align-self:baseline}
  .srow .note{grid-column:1;grid-row:2;font-size:12px;line-height:1.45;color:var(--dim)}
}

@media(max-width:640px){.docs .doc{min-width:100%}}

/* MARKER-INVEST-MOBILE — one column, bigger targets, and inputs at 16px so
   iOS doesn't zoom the whole page the moment someone taps a field. */
@media(max-width:640px){
  .wrap{padding:0 20px}
  nav .wrap{height:56px}
  section{padding:32px 0}
  section.hero{padding:34px 0 28px}
  h1{font-size:29px;letter-spacing:-1.2px;line-height:1.12}
  h2{font-size:20px}
  .lede{font-size:15px}
  .prog{padding:18px}
  .progtop .big{font-size:27px}
  .key{gap:10px;font-size:11.5px}
  .key span{flex:0 0 100%}
  .steps{padding:2px 16px}
  .step{gap:13px;padding:18px 0}
  .stepn{flex:0 0 26px;height:26px;font-size:12px}
  input,select,textarea{max-width:100%;font-size:16px;padding:12px 13px}
  .btn{display:block;width:100%;text-align:center;padding:14px 18px;font-size:15.5px}
  .wire span{display:block;width:auto;margin-bottom:-4px}
  .who{font-size:11.5px}
}
/* Disclosure rows: collapsed detail is still on the page, just not costing
   scroll before the reader has decided they care. */
details.m{border-top:1px solid var(--line);padding:14px 0;margin-top:20px}
details.m summary{list-style:none;cursor:pointer;font-size:13.5px;font-weight:600;color:var(--text);
  display:flex;align-items:center;gap:8px}
details.m summary::-webkit-details-marker{display:none}
details.m summary::after{content:"+";margin-left:auto;color:var(--lime);font-size:17px;line-height:1}
details.m[open] summary::after{content:"\2013"}
details.m .inner{padding-top:10px}
</style>
