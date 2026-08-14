@verbatim
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intake — Investment Opportunity</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">{{-- MARKER-SELFHOST-FONTS-2 --}}
<style>
:root{
  --bg:#0B0F0C; --panel:#111710; --panel2:#151C14;
  --line:#1F2A1E; --line-soft:#161f16;
  --text:#F2F4EE; --body:#8D9A8B; --dim:#5F6A5E;
  --lime:#BEF264; --lime-soft:rgba(190,242,100,.09); --lime-line:rgba(190,242,100,.34);
  --max:1080px;
}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,sans-serif;
  font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased}
.wrap{max-width:var(--max);margin:0 auto;padding:0 28px}

/* ---------- nav ---------- */
nav{position:sticky;top:0;z-index:50;background:rgba(11,15,12,.88);backdrop-filter:blur(14px);
  border-bottom:1px solid var(--line)}
nav .wrap{display:flex;align-items:center;gap:18px;height:66px}
.brand{display:flex;align-items:center;gap:10px;font-size:19px;font-weight:700;letter-spacing:-.5px}
.brand img{width:27px;height:27px;display:block}
.navlinks{margin-left:auto;display:flex;gap:26px}
.navlinks a{color:var(--body);text-decoration:none;font-size:13.5px;font-weight:500}
.navlinks a:hover{color:var(--lime)}
.invite{font-size:10px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;
  color:var(--lime);border:1px solid var(--lime-line);background:var(--lime-soft);
  border-radius:5px;padding:4px 9px;white-space:nowrap}
@media(max-width:820px){.navlinks{display:none}}

/* ---------- type ---------- */
.eyebrow{font-size:11px;font-weight:600;letter-spacing:2.6px;text-transform:uppercase;color:var(--lime)}
.eyebrow.mute{color:var(--dim)}
h1{font-size:clamp(38px,6.2vw,68px);font-weight:800;letter-spacing:-2.4px;line-height:1.03;margin:20px 0 0}
h1 .l{color:var(--lime)}
h2{font-size:clamp(27px,3.6vw,40px);font-weight:800;letter-spacing:-1.2px;line-height:1.12;margin:14px 0 0}
h3{font-size:16px;font-weight:600;letter-spacing:-.2px}
p{color:var(--body);font-weight:300;line-height:1.65}
.lede{font-size:clamp(16px,1.9vw,19px);max-width:60ch;margin-top:22px}
b,strong{color:var(--text);font-weight:600}
section{padding:82px 0;border-top:1px solid var(--line)}
section.hero{border-top:0;padding:96px 0 88px}
.sub{font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--dim);
  padding-bottom:11px;border-bottom:1px solid var(--line);margin:44px 0 22px}

/* ---------- bits ---------- */
.tags{display:flex;flex-wrap:wrap;gap:34px;margin-top:44px;padding-top:24px;border-top:1px solid var(--line)}
.tags div b{display:block;font-size:13px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--lime)}
.tags div span{font-size:11.5px;letter-spacing:1.4px;text-transform:uppercase;color:var(--dim);font-weight:500}

.grid{display:grid;gap:14px;margin-top:26px}
.g2{grid-template-columns:repeat(2,1fr)}
.g3{grid-template-columns:repeat(3,1fr)}
.g4{grid-template-columns:repeat(4,1fr)}
@media(max-width:860px){.g3,.g4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.g2,.g3,.g4{grid-template-columns:1fr}}

.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:24px}
.card.hi{border-color:var(--lime-line)}
.card .n{font-size:clamp(28px,4vw,40px);font-weight:800;color:var(--lime);letter-spacing:-1.6px;line-height:1}
.card .n.w{color:var(--text)}
.card .k{font-size:10.5px;font-weight:600;letter-spacing:1.7px;text-transform:uppercase;color:var(--dim);margin-top:10px}
.card p{font-size:14px;margin-top:12px}
.card h3+p{margin-top:8px}

table{width:100%;border-collapse:collapse;margin-top:22px}
th{font-size:10px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:var(--dim);
  text-align:left;padding:11px 0;border-bottom:1px solid var(--line)}
th.r,td.r{text-align:right}
td{font-size:14.5px;font-weight:300;color:var(--body);padding:13px 0;border-bottom:1px solid var(--line-soft);vertical-align:top}
td.k{color:var(--text);font-weight:500}
td.num{font-variant-numeric:tabular-nums;color:var(--text);font-weight:600;white-space:nowrap}
tr.tot td{border-bottom:0;border-top:1px solid var(--line);padding-top:16px;color:var(--text);font-weight:600}
tr.tot td.k{color:var(--lime);font-weight:700}
@media(max-width:620px){td,th{font-size:13px}}

ul.tick{list-style:none;margin-top:18px}
p.pull{font-size:22px;font-weight:600;color:var(--text);line-height:1.3;letter-spacing:-.4px;border-left:3px solid var(--lime);padding-left:18px;margin:22px 0 8px}
@media(max-width:560px){p.pull{font-size:18px}}
ul.tick.cols2{columns:2;column-gap:40px}
@media(max-width:560px){ul.tick.cols2{columns:1}}
ul.tick li{font-size:15px;font-weight:300;color:var(--body);line-height:1.55;padding-left:24px;
  position:relative;margin-bottom:12px}
ul.tick li::before{content:"→";position:absolute;left:0;color:var(--lime);font-weight:600}
ul.tick li b{color:var(--text)}

.note{border-left:2px solid var(--lime-line);padding:4px 0 4px 16px;margin-top:26px;
  font-size:14px;color:var(--body);font-weight:300}
.warn{border:1px dashed #3A473A;border-radius:10px;padding:18px 20px;margin-top:26px}
.warn p{font-size:13.5px}

/* ---------- calculator ---------- */
.calc{background:var(--panel2);border:1px solid var(--line);border-radius:14px;padding:28px;margin-top:28px}
.calcrow{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
.calcout{font-size:clamp(34px,5.2vw,50px);font-weight:800;color:var(--lime);letter-spacing:-2px;line-height:1}
.calclab{font-size:11px;font-weight:600;letter-spacing:1.7px;text-transform:uppercase;color:var(--dim)}
input[type=range]{-webkit-appearance:none;appearance:none;width:100%;height:4px;border-radius:3px;
  background:var(--line);margin-top:26px;outline:none}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:22px;height:22px;border-radius:50%;
  background:var(--lime);cursor:pointer;border:3px solid var(--bg)}
input[type=range]::-moz-range-thumb{width:22px;height:22px;border-radius:50%;background:var(--lime);
  cursor:pointer;border:3px solid var(--bg)}
.ticks{display:flex;justify-content:space-between;margin-top:10px;font-size:11px;color:var(--dim);
  letter-spacing:1px;font-weight:500}
.split{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:24px;
  padding-top:20px;border-top:1px solid var(--line)}
.split div span{display:block;font-size:10.5px;letter-spacing:1.5px;text-transform:uppercase;color:var(--dim);font-weight:600}
.split div b{font-size:19px;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums}
@media(max-width:560px){.split{grid-template-columns:1fr;gap:12px}}

/* ---------- cta ---------- */
.cta{border:1px solid var(--lime-line);border-radius:14px;padding:34px;margin-top:34px;
  display:flex;gap:26px;align-items:center;flex-wrap:wrap;background:var(--lime-soft)}
.cta .txt{flex:1;min-width:260px}
.cta h3{font-size:22px;letter-spacing:-.6px}
.cta p{font-size:14.5px;margin-top:7px}
.btn{display:inline-block;background:var(--lime);color:#0B0F0C;text-decoration:none;
  font-size:14.5px;font-weight:700;letter-spacing:-.2px;padding:14px 26px;border-radius:9px;white-space:nowrap;border:0;cursor:pointer;font-family:inherit}
.btn:hover{filter:brightness(1.08)}
.btn.ghost{background:transparent;color:var(--lime);border:1px solid var(--lime-line)}

footer{border-top:1px solid var(--line);padding:40px 0 60px}
footer .wrap{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;
  font-size:11.5px;letter-spacing:1.5px;text-transform:uppercase;color:var(--dim);font-weight:500}
</style><meta name="robots" content="noindex,nofollow,noarchive">
<style>
.leadform{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.leadform input{flex:1 1 200px;background:var(--panel2,#151C14);border:1px solid var(--line,#1F2A1E);border-radius:8px;padding:12px 14px;color:inherit;font:inherit}
.leadform input:focus{outline:none;border-color:var(--lime,#BEF264)}
.leadform button{flex:0 0 auto}
</style>
</head>
<body>

<nav><div class="wrap">
  <span class="brand"><img src="data:image/png;base64,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" alt=""> intake</span>
  <span class="invite">Invitation only</span>
  <span class="navlinks">
    <a href="#problem">Problem</a><a href="#product">Product</a><a href="#model">Model</a>
    <a href="#market">Market</a><a href="#ask">The ask</a>
  </span>
</div></nav>

<section class="hero"><div class="wrap">
  <div class="eyebrow">Investment opportunity · August 2026</div>
  <h1>For businesses that<br>take <span class="l">appointments</span>, sell<br><span class="l">retail</span> and teach <span class="l">classes</span>.</h1>
  <p class="lede">Less friction, better retention, and a way to win customers back when you need it — so you
    can focus on the business instead of the apps. Intake is point of sale, service work orders, scheduling,
    inventory and customer retention in one platform, built for independent bike shops first.</p>
  <div class="tags">
    <div><b>$100,000</b><span>Raise</span></div>
    <div><b>10%</b><span>At a $1M cap</span></div>
    <div><b>12 months</b><span>Growth plan</span></div>
    <div><b>Pre-revenue</b><span>Product live in production</span></div>
  </div>
</div></section>

<section id="problem"><div class="wrap">
  <div class="eyebrow mute">The problem</div>
  <h2>Shops run four systems<br>that don't talk to each other.</h2>
  <p class="lede">A shop books a repair in one tool, rings the sale in another, tracks the part on a
    supplier's website and emails the customer from a third. None of them share a customer record. The
    shop pays for all of it, and staff reconcile the gaps by hand every evening.</p>

  <div class="sub">What one three-location shop was actually paying</div>
  <table>
    <tbody>
      <tr><td class="k">Ascend POS · 3 locations</td><td class="num r">$750</td><td class="r">Retail only</td></tr>
      <tr><td class="k">Shopify + add-ons</td><td class="num r">$680–880</td><td class="r">A second catalog to maintain</td></tr>
      <tr><td class="k">MasterLinq</td><td class="num r">$550 + fees</td><td class="r">Supplier data the register never saw</td></tr>
      <tr><td class="k">Constant Contact</td><td class="num r">$185</td><td class="r">Marketing, disconnected</td></tr>
      <tr><td class="k">Booqable · Freshdesk</td><td class="num r">$109</td><td class="r">Rentals and inbox</td></tr>
      <tr class="tot"><td class="k">Every month</td><td class="num r">$2,274–2,474</td><td class="r">And still no two-way texting</td></tr>
    </tbody>
  </table>

  <div class="grid g2">
    <div class="card"><div class="n w">$775</div><div class="k">Intake, same three locations</div>
      <p>Covers all three locations, packs included — a single-location shop lands nearer $280. One
        customer record from the booking through the repair to the receipt and the follow-up.</p></div>
    <div class="card hi"><div class="n">$18–20k</div><div class="k">Saved per year</div>
      <p>The saving is real, but it isn't the point. The point is the shop stops paying people to
        reconcile software.</p></div>
  </div>
  <div class="sub">Why it costs a third as much</div>
  <p>One team builds all of it, so the shop pays for one product rather than a stack of them, each with its own
    sales force, support desk and margin. And nothing has to be glued together: the booking, the work order, the
    sale and the follow-up are one record, so there is no integration layer to buy or maintain.</p>
  <p class="pull">The saving isn't a discount on the same stack. It's a stack that isn't there.</p>
  <div class="warn"><p>Figures are one shop's own invoices, not list pricing. They are used here because
    they are verifiable, not because they are typical.</p></div>
</div></section>

<section id="product"><div class="wrap">
  <div class="eyebrow mute">Where we are</div>
  <h2>Not a deck. A running platform.</h2>
  <p class="lede">Intake starts in specialty bicycle retail because it is a service business, a retail
    business and a rental business at once — the hardest version of the problem. A platform that runs a
    bike shop runs a ski shop, a studio or a repair shop without being rebuilt.</p>

  <div class="grid g4">
    <div class="card"><div class="n w">Live</div><div class="k">Multi-tenant in production</div></div>
    <div class="card"><div class="n w">~97k</div><div class="k">Catalog rows, 3 distributors</div></div>
    <div class="card"><div class="n w">1</div><div class="k">Founding shop converting</div></div>
    <div class="card"><div class="n w">8</div><div class="k">States under a founding rep group</div></div>
  </div>

  <div class="sub">Why this founder, in this market</div>
  <ul class="tick">
    <li><b>Owner and general manager of a multi-store specialty bicycle retailer</b></li>
    <li><b>Did every job in it</b> — buying, building, hiring, scheduling, opening new locations, and every
      vendor relationship</li>
    <li><b>70+ cycling events produced</b> through Velo Northwest, and a component brand designed and shipped</li>
    <li><b>A working mobile service business</b>, Ground Control, used as the platform's own daily test tenant</li>
    <li><b>An eight-state founding rep group</b> — the first agency to carry Intake, on terms set to get it
      into shops fast</li>
    <li><b>Three distributor integrations</b> with cross-distributor product matching — months of work and
      supplier relationships a competitor starts from zero on</li>
  </ul>

  <div class="sub">Why Intake wins</div>
  <table>
    <tbody>
      <tr><td class="k">Generic POS</td><td>Retail first; service bolted on around it</td></tr>
      <tr><td class="k">Booking platforms</td><td>Appointments first; weak retail and inventory</td></tr>
      <tr><td class="k">Bike-industry POS</td><td>Industry-specific retail, fragmented service and customer tools</td></tr>
      <tr class="tot"><td class="k">Intake</td><td>Service, retail, inventory, booking and customer lifecycle built as one record</td></tr>
    </tbody>
  </table>
  <div class="note">The channel matters more than it looks. Sales reps already walk into every shop in the
    territory every few weeks. Intake gets sold by people the shop already trusts, instead of by cold
    outbound into an industry that ignores it.</div>
</div></section>

<section id="model"><div class="wrap">
  <div class="eyebrow mute">The model</div>
  <h2>Two lines, one set of shops.</h2>
  <p class="lede">Subscription scales with how many businesses run Intake. Payments scale with how much
    money those same businesses take. The second one costs no additional selling.</p>

  <div class="sub">One — subscription</div>
  <table>
    <thead><tr><th>Tier</th><th class="r">Price</th><th class="r">Year one</th><th class="r">Year two</th></tr></thead>
    <tbody>
      <tr><td class="k">Starter — solo practitioners<div style="font-size:11.5px;color:var(--dim);font-weight:300">Massage, acupuncture, mobile mechanics</div></td><td class="num r">$29</td><td class="num r">100</td><td class="num r">300</td></tr>
      <tr><td class="k">Branded — fitness studios<div style="font-size:11.5px;color:var(--dim);font-weight:300">Classes, memberships and packs, gift cards, light retail</div></td><td class="num r">$79</td><td class="num r">60</td><td class="num r">200</td></tr>
      <tr><td class="k">Scale — bike &amp; outdoor shops<div style="font-size:11.5px;color:var(--dim);font-weight:300">Bike, ski, snowboard, paddle — same schedule, same maths</div></td><td class="num r">$280 avg</td><td class="num r">50</td><td class="num r">150</td></tr>
      <tr class="tot"><td class="k">Accounts</td><td class="num r">—</td><td class="num r">210</td><td class="num r">650</td></tr>
    </tbody>
  </table>
  <div class="grid g2">
    <div class="card"><div class="n w">$260k</div><div class="k">Year one ARR · $21.6k MRR</div></div>
    <div class="card hi"><div class="n">$798k</div><div class="k">Year two ARR · $66.5k MRR</div></div>
  </div>
  <div class="note">The 50 Scale shops are sold by the founder and the eight-state rep channel. The Starter and
    Branded accounts are the job of the $20k marketing line — self-serve tiers sold by the product, not by hand.
    Only the Scale ramp assumes zero marketing help.</div>

  <div class="sub">Two — payments</div>
  <p>Every account taking money through Intake sends card volume across the platform — <b>$27.5M in year
    one, $86M in year two</b>. Today that runs at straight pass-through and earns nothing, deliberately,
    because rate negotiation follows volume rather than the other way round.</p>
  <p class="note">Projected volume = accounts × average annual card volume × the share expected to run payments
    through Intake.</p>

  <div class="calc">
    <div class="calcrow">
      <div class="calcout" id="out">$1.07M</div>
      <div class="calclab">Year two total revenue at <b id="bps" style="color:var(--lime)">25 bps</b></div>
    </div>
    <input type="range" id="rate" min="0" max="100" step="5" value="25">
    <div class="ticks"><span>0 — PASS-THROUGH</span><span>50</span><span>100 BPS</span></div>
    <div class="split">
      <div><span>Subscription</span><b>$798,000</b></div>
      <div><span>Onboarding</span><b>$60,000</b></div>
      <div><span>Payments</span><b id="pay">$215,000</b></div>
    </div>
  </div>
  <div class="note">25 bps — a quarter of one percent — is deliberately the low end of what platforms in
    this position charge. Shops compare processing rates out loud, and the rate should never be the reason
    one leaves. Everything above it is upside this page doesn't claim. Fitness is the strongest of the
    three: memberships and class packs bill on card-on-file whether or not anyone walks in, and gift cards
    add a second prepaid line on top.</div>

  <div class="sub">Together</div>
  <table>
    <thead><tr><th></th><th class="r">Year one</th><th class="r">Year two</th></tr></thead>
    <tbody>
      <tr><td class="k">Subscription ARR</td><td class="num r">$260k</td><td class="num r">$798k</td></tr>
      <tr><td class="k">Onboarding and migration</td><td class="num r">$30k</td><td class="num r">$60k</td></tr>
      <tr><td class="k">Payments at 25 bps</td><td class="num r">$69k</td><td class="num r">$215k</td></tr>
      <tr class="tot"><td class="k">Total revenue</td><td class="num r">$358k</td><td class="num r">$1.07M</td></tr>
    </tbody>
  </table>
</div></section>

<section id="market"><div class="wrap">
  <div class="eyebrow mute">The market</div>
  <h2>Start narrow. The<br>architecture goes wide.</h2>

  <div class="grid g2">
    <div class="card hi"><div class="n">10,004</div><div class="k">US bicycle dealership &amp; repair businesses</div>
      <p>The beachhead. Year two needs <b>150 of them — 1.5%</b> of a market I have worked in for twenty
        years.</p></div>
    <div class="card"><div class="n w">132,902</div><div class="k">Reachable on the same motion</div>
      <p>Bicycle, motorcycle and fitness. Same workflow, same kind of owner, same channel: book it in, work
        on it, sell parts alongside the work, get the customer back.</p></div>
  </div>

  <div class="sub">The categories, by count</div>
  <table>
    <thead><tr><th>Category</th><th class="r">US businesses</th><th class="r">Market size</th></tr></thead>
    <tbody>
      <tr><td class="k">Gyms &amp; fitness clubs</td><td class="num r">107,751</td><td class="r">$102.1M</td></tr>
      <tr><td class="k">Motorcycle dealership &amp; repair</td><td class="num r">15,147</td><td class="r">$50.9M</td></tr>
      <tr><td class="k">Bicycle dealership &amp; repair</td><td class="num r">10,004</td><td class="r">$33.6M</td></tr>
      <tr class="tot"><td class="k">Total addressable</td><td class="num r">132,902</td><td class="r">$186.7M ARR</td></tr>
    </tbody>
  </table>
  <p class="note">Business counts: IBISWorld, 2026. Ski, snowboard, paddle and outdoor specialty shops sell
    on the same schedule as bike and are counted as Scale accounts in the plan — but they are absent from
    the table above, because no industry category isolates them from big-box sporting goods, and a number
    that can't be sourced doesn't belong here. The total is lower than the real one. Subscription TAM is
    calculated at Intake's own published rates, not a category average.</p>

  <div class="sub">What 5% looks like, ten years out</div>
  <div class="grid g2">
    <div class="card"><div class="n w">6,645</div><div class="k">Accounts</div>
      <p>Every one in a category reachable on the motion this business already runs.</p></div>
    <div class="card hi"><div class="n">$9.3M</div><div class="k">Subscription ARR</div>
      <p>Before any payments margin on the volume those accounts would process.</p></div>
  </div>
  <div class="sub">Near, and far</div>
  <div class="grid g2">
    <div class="card"><h3>Already in the plan — Scale tier</h3>
      <p>Bike, ski, snowboard and paddle shops run on the same schedule and the same maths. Service, retail
        and rental at once, seasonal queues, rental fleets and damage deposits — often the same owner in
        the same building, reachable through the same reps.</p></div>
    <div class="card"><h3>Further out — new channel needed</h3>
      <p>Marine, small engine and powersports service. Serialised assets, seasonal queues, parts against a
        job — the same system with a different noun on the work order, and in no forecast on this
        page.</p></div>
  </div>
  <div class="sub">Same workflow, not in any forecast</div>
  <ul class="tick cols2">
    <li>Powersports</li><li>Marine</li><li>Small engine &amp; mower</li>
    <li>Sewing machine</li><li>Musical instrument repair</li><li>Computer &amp; phone repair</li>
    <li>Watch &amp; jewelry</li><li>Appliance service</li><li>Pet grooming</li><li>Salons &amp; barbers</li>
  </ul>
  <div class="warn"><p>The horizontal is a claim about product architecture as much as distribution. Bike,
    ski and paddle share a rep channel; fitness does not, and will need its own route. That is stated here
    rather than left to be discovered.</p></div>
</div></section>

<section id="ask"><div class="wrap">
  <div class="eyebrow mute">The ask</div>
  <h2>$100,000. A twelve-month growth plan.</h2>
  <p class="lede">The $100k is what carries Intake from one founding shop to 210 accounts — the level where the next
    conversation is about growth, not existence.</p>

  <div class="grid g3">
    <div class="card hi"><div class="n">$100k</div><div class="k">Raise</div></div>
    <div class="card"><div class="n w">10%</div><div class="k">At a $1M cap</div></div>
    <div class="card"><div class="n w">$260k + $69k</div><div class="k">Subscription ARR + modeled payments, month twelve</div></div>
  </div>

  <div class="sub">Use of funds</div>
  <table>
    <tbody>
      <tr><td class="k">Contract engineering</td><td class="num r">$38k</td><td>A second pair of hands on
        the product, so development continues rather than stopping every time I am in a shop.</td></tr>
      <tr><td class="k">Founder draw</td><td class="num r">$36k</td><td>Twelve months selling and onboarding
        full time. Not overhead — the plan above assumes 210 accounts brought in by hand, and that only
        happens if I am on the road rather than earning a living elsewhere.</td></tr>
      <tr><td class="k">Marketing</td><td class="num r">$20k</td><td>Bringing businesses in through channels
        other than hand-selling. No revenue from this spend appears anywhere in the numbers above.</td></tr>
      <tr><td class="k">Infrastructure and tools</td><td class="num r">$6k</td><td>Hosting, backups,
        monitoring, and the software needed to run the business for a year.</td></tr>
    </tbody>
  </table>

  <div class="sub">Structure</div>
  <p>Proposed as a post-money SAFE at a <b>$1M cap</b> — $100k for 10% — on the standard template, which is
    free to paper. A priced round at the same number reaches the same place with roughly $10–20k of legal
    cost attached, a material share of a $100k raise. The same terms apply to every participant.</p>


  <div class="sub">What it costs to run</div>
  <p>Intake is software, so most of what it earns it keeps. Hosting scales per tenant and costs cents a day
    to serve one. Messaging and telephony are billed to tenants at cost with no markup. Payment processing
    carries no cost behind it — the 25 bps is margin above cost, not revenue with an expense attached.</p>
  <div class="grid g3">
    <div class="card hi"><div class="n">~95%</div><div class="k">Gross margin</div>
      <p>Ordinary for software, and it does not get worse with scale.</p></div>
    <div class="card"><div class="n w">~$18k</div><div class="k">Year-two infrastructure</div>
      <p>Hosting, backups and monitoring across 650 accounts.</p></div>
    <div class="card"><div class="n w">~$69k</div><div class="k">Year-two channel cost</div>
      <p>Founding-rep commission on territory dealers. This is the customer acquisition cost — it replaces
        ad spend and cold outbound rather than sitting on top of them.</p></div>
  </div>
  <div class="warn"><p><b>The gap this raise doesn't close.</b> 650 accounts in year two need support,
    onboarding and migration capacity that $100,000 doesn't buy. The plan reaches year one comfortably;
    year two means either a second raise or growing more slowly and funding hires out of revenue. Both are
    real options and neither is decided.</p></div>

  <div class="sub">What has to go right</div>
  <table>
    <tbody>
      <tr><td class="k">Shops have to pay</td><td>Nobody has yet paid Intake a subscription, so willingness
        to pay is unproven and every figure above is a forecast. Against that: pricing is published, and a
        founding shop is converting its full point-of-sale system now.</td></tr>
      <tr><td class="k">One person has to become two</td><td>Built and run by a single founder, which caps
        how fast anything moves. This raise is aimed squarely at that — but the dependency doesn't vanish
        the day the money lands.</td></tr>
      <tr><td class="k">The channel has to earn its cost</td><td>The founding rep agreement pays 25% of
        subscription for a dealer's first year and 15% after. Revenue above is gross and that commission is a
        real cost against it — but it is the acquisition cost, not an addition to one: nothing is spent on ads
        or outbound to land a dealer the reps bring. These are founding terms for the first territory; later
        agreements are expected to be lower.</td></tr>
      <tr><td class="k">The payments rate has to be negotiated</td><td>Processing runs at pass-through today
        and earns nothing. Strip the modelled 25 bps out entirely and year two is $858k rather than $1.07M —
        the base business doesn't depend on it.</td></tr>
      <tr><td class="k">A hard market cuts both ways</td><td>Specialty bicycle retail is under real margin
        pressure. Part of why shops are receptive to cutting software cost — and part of why some will close
        before they ever become customers.</td></tr>
    </tbody>
  </table>

  <div class="cta">
    <div class="txt">
      <h3>Want to talk it through?</h3>
      <p>This page is shared by invitation. Happy to walk through the model, the assumptions behind it, or
        put you in front of the product running in a real shop.</p>
    </div>
    <a class="btn" href="mailto:josh@intake.works?subject=Intake%20investment">Get in touch</a>
    <a class="btn ghost" href="#model">See the numbers again</a>
  </div>
@endverbatim

  <div class="sub">Or leave your details</div>
  @if (session('invest_lead_ok'))
    <div class="warn"><p>Thanks — noted. You will hear from Josh directly.</p></div>
  @else
    <form method="POST" action="{{ route('invest.lead', ['token' => $token->token]) }}" class="leadform">
      @csrf
      @if ($errors->any())
        <div class="warn"><p>{{ $errors->first() }}</p></div>
      @endif
      <input type="text" name="name" placeholder="Name" value="{{ old('name') }}" required>
      <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required>
      <input type="text" name="note" placeholder="Anything you want to ask (optional)" value="{{ old('note') }}">
      <input type="text" name="company_website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off">
      <button type="submit" class="btn">Send</button>
    </form>
  @endif
@verbatim
</div></section>

<footer><div class="wrap">
  <span>Intake · intake.works</span>
  <span>Confidential — shared by invitation</span>
</div></footer>

<script>
(function(){
  var SUB = 798000, ONB = 60000, GMV = 86000000;
  var rate = document.getElementById('rate'),
      out  = document.getElementById('out'),
      bps  = document.getElementById('bps'),
      pay  = document.getElementById('pay');
  function money(n){
    if (n >= 1000000) return '$' + (n/1000000).toFixed(2).replace(/\.?0+$/,'') + 'M';
    return '$' + Math.round(n/1000) + 'k';
  }
  function draw(){
    var b = +rate.value, p = GMV * b / 10000, total = SUB + ONB + p;
    bps.textContent = b + ' bps';
    pay.textContent = '$' + p.toLocaleString('en-US', {maximumFractionDigits:0});
    out.textContent = money(total);
  }
  rate.addEventListener('input', draw);
  draw();
})();
</script>
</body></html>
@endverbatim
