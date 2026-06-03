{{-- MARKER-PATCH-206 — Branded invoice, PREVIEW ONLY (browser/iframe). --}}
@php
  $isPaid = $terms === 'paid';
  $acc    = $tenant->accent_color ?? '#D94F1E';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{ --acc:{{ $acc }}; --acc-deep:#9c3a14; --acc-tint:#FBEDE7; --ink:#111; --ink2:#444; --ink3:#666; --ink4:#8a8a8a; --line:#f0f0ee; --line2:#e8e8e4; --paper2:#f8f8f6; }
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',sans-serif;color:var(--ink);-webkit-font-smoothing:antialiased;background:#fff;width:816px}
  .num{font-variant-numeric:tabular-nums}
  .band{background:var(--acc);color:#fff;padding:40px 50px 34px;position:relative;overflow:hidden}
  .band::after{content:'';position:absolute;right:-70px;top:-70px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.07)}
  .btop{display:flex;justify-content:space-between;align-items:flex-start;position:relative;z-index:1}
  .brand{display:flex;align-items:center;gap:12px}
  .bmark{width:42px;height:42px;border-radius:11px;background:#fff;color:var(--acc);display:grid;place-items:center;font-size:22px;font-weight:800;letter-spacing:-.04em}
  .bshop{font-size:17px;font-weight:700}.btag{font-size:11.5px;color:rgba(255,255,255,.78);margin-top:2px}
  .bpill{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:rgba(0,0,0,.18);padding:6px 13px;border-radius:100px}
  .brow{display:flex;align-items:flex-end;justify-content:space-between;margin-top:32px;position:relative;z-index:1}
  .brow h1{font-size:40px;font-weight:800;letter-spacing:-.03em;line-height:.9}
  .brow .sub{font-size:12.5px;color:rgba(255,255,255,.82);margin-top:7px}.brow .sub b{color:#fff}
  .bdue{text-align:right}.bdue .dl{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.72);font-weight:700}
  .bdue .dv{font-size:30px;font-weight:800;letter-spacing:-.02em;margin-top:3px}
  .strip{display:flex;background:#1a1410;color:#fff}
  .bm{flex:1;padding:14px 22px;border-right:1px solid rgba(255,255,255,.12)}.bm:last-child{border-right:none}
  .bm .ml{font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.5);font-weight:700}
  .bm .mv{font-size:13.5px;font-weight:600;margin-top:4px}
  .body{padding:30px 50px 0}
  .summary{margin-bottom:20px}.summary .l{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--ink4);font-weight:700}
  .summary .n{font-size:15px;font-weight:700;margin-top:5px}.summary .d{font-size:12.5px;color:var(--ink3);margin-top:3px}
  .card{border:1px solid var(--line2);border-radius:13px;overflow:hidden;margin-bottom:13px}
  .card-h{display:flex;align-items:center;gap:11px;padding:13px 18px;background:var(--paper2);border-bottom:1px solid var(--line)}
  .ix{width:24px;height:24px;border-radius:7px;background:var(--acc-tint);color:var(--acc-deep);display:grid;place-items:center;font-size:12px;font-weight:700;flex-shrink:0}
  .card-h .an{font-size:14px;font-weight:700;flex:1}
  .card-h .as{font-size:13px;font-weight:700}
  .li{display:flex;justify-content:space-between;padding:11px 18px;border-bottom:1px solid var(--line);font-size:14px}
  .li:last-child{border-bottom:none}.li .nm{font-weight:500}.li .a{font-weight:600}
  .li.add{padding-left:30px}.li.add .nm{font-weight:400;color:var(--ink2);font-size:13px}.li.add .nm::before{content:'+ ';color:var(--acc);font-weight:700}
  .li.add .a{font-weight:400;color:var(--ink2);font-size:13px}
  .note{background:var(--acc-tint);border-radius:11px;padding:15px 18px;margin:4px 0 16px}
  .note .l{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--acc-deep);font-weight:700;margin-bottom:5px}
  .note .t{font-size:12.5px;color:var(--ink2);line-height:1.6}
  .foot{display:flex;gap:24px;align-items:flex-start}
  .cta{flex:1;background:var(--paper2);border:1px solid var(--line2);border-radius:13px;padding:18px 20px;display:flex;gap:16px;align-items:center}
  .qr{width:72px;height:72px;flex-shrink:0;border-radius:9px;background:#fff;padding:6px;border:1px solid var(--line2)}
  .cta-t{font-size:13.5px;font-weight:700;color:var(--acc-deep)}.cta-d{font-size:12px;color:var(--ink2);line-height:1.45;margin-top:4px}.cta-u{font-size:12px;font-weight:600;color:var(--acc-deep);margin-top:6px}
  .stamp{display:inline-flex;align-items:center;gap:6px;border:2px solid var(--acc);color:var(--acc-deep);font-weight:800;text-transform:uppercase;letter-spacing:.08em;font-size:13px;padding:6px 14px;border-radius:8px;transform:rotate(-3deg)}
  .tot{width:262px;flex-shrink:0}
  .tr{display:flex;justify-content:space-between;font-size:13px;padding:7px 0;color:var(--ink2)}.tr .num{color:var(--ink);font-weight:500}.tr.disc{color:var(--ink3)}
  .grand{margin-top:7px;background:var(--ink);color:#fff;border-radius:12px;padding:15px 17px;display:flex;justify-content:space-between;align-items:center}
  .grand .gl{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.7);font-weight:700}
  .grand .gl small{display:block;color:#fff;font-size:13.5px;text-transform:none;letter-spacing:0;font-weight:700;margin-top:2px}
  .grand .gv{font-size:25px;font-weight:800;letter-spacing:-.02em}
  .terms{margin:28px 50px 0;padding:18px 0 38px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:26px}
  .terms .tx{font-size:11px;color:var(--ink4);line-height:1.7;max-width:58%}
  .terms .bl{font-size:12px;font-weight:700;color:var(--acc)}.terms .bl small{display:block;color:var(--ink4);font-weight:400;margin-top:2px}
</style>
</head>
<body>
  <div class="band">
    <div class="btop">
      <div class="brand"><div class="bmark">{{ strtoupper(substr($tenant->name,0,1)) }}</div>
        <div><div class="bshop">{{ $tenant->name }}</div>@if($tenant->tagline ?? false)<div class="btag">{{ $tenant->tagline }}</div>@endif</div>
      </div>
      <div class="bpill">{{ $isPaid ? 'Paid' : ($terms === 'due_now' ? 'Balance due' : 'Due on completion') }}</div>
    </div>
    <div class="brow">
      <div><h1>Invoice</h1><div class="sub">Work order <b>{{ $number }}</b></div></div>
      <div class="bdue"><div class="dl">{{ $isPaid ? 'Total paid' : 'Amount due' }}</div><div class="dv num">{{ format_money($isPaid ? $total : $balance) }}</div></div>
    </div>
  </div>

  <div class="strip">
    <div class="bm"><div class="ml">Billed to</div><div class="mv">{{ $customer['name'] }}</div></div>
    <div class="bm"><div class="ml">Issued</div><div class="mv">{{ now()->format('M j, Y') }}</div></div>
    <div class="bm"><div class="ml">Terms</div><div class="mv">{{ $isPaid ? 'Paid' : ($terms === 'due_now' ? 'Due now' : 'On completion') }}</div></div>
    <div class="bm"><div class="ml">Assets</div><div class="mv">{{ count($assets) }} serviced</div></div>
  </div>

  <div class="body">
    <div class="summary"><div class="l">Service summary</div><div class="n">{{ count($assets) }} {{ \Illuminate\Support\Str::plural('asset', count($assets)) }}</div><div class="d">Completed {{ now()->format('M j, Y') }} · {{ $customer['email'] }}</div></div>

    @foreach($assets as $i => $a)
      <div class="card">
        <div class="card-h"><div class="ix">{{ $i + 1 }}</div><div class="an">{{ $a['name'] }}</div><div class="as num">{{ format_money($a['subtotal']) }}</div></div>
        @foreach($a['lines'] as $l)
          <div class="li {{ $l['add'] ? 'add' : '' }}"><div class="nm">{{ $l['name'] }}</div><div class="a num">{{ format_money($l['cents']) }}</div></div>
        @endforeach
      </div>
    @endforeach

    @if(count($loose))
      <div class="card">
        <div class="card-h"><div class="ix">+</div><div class="an">Shop &amp; parts</div><div class="as"></div></div>
        @foreach($loose as $l)
          <div class="li {{ $l['add'] ? 'add' : '' }}"><div class="nm">{{ $l['name'] }}</div><div class="a num">{{ format_money($l['cents']) }}</div></div>
        @endforeach
      </div>
    @endif

    @if(trim((string) $note) !== '')
      <div class="note"><div class="l">Note from the shop</div><div class="t">{!! nl2br(e($note)) !!}</div></div>
    @endif

    <div class="foot">
      @if($isPaid)
        <div class="cta" style="background:#fff;border:none;gap:14px"><div class="stamp">&checkmark; Paid in full</div><div class="cta-d" style="margin:0">Thanks for trusting {{ $tenant->name }} with your work.</div></div>
      @else
        <div class="cta">
          <div class="qr"><svg viewBox="0 0 100 100" width="100%" height="100%" shape-rendering="crispEdges"><rect width="100" height="100" fill="#fff"/><g fill="#1a1410"><rect x="6" y="6" width="22" height="22"/><rect x="10" y="10" width="14" height="14" fill="#fff"/><rect x="13" y="13" width="8" height="8"/><rect x="72" y="6" width="22" height="22"/><rect x="76" y="10" width="14" height="14" fill="#fff"/><rect x="79" y="13" width="8" height="8"/><rect x="6" y="72" width="22" height="22"/><rect x="10" y="76" width="14" height="14" fill="#fff"/><rect x="13" y="79" width="8" height="8"/><rect x="40" y="40" width="6" height="6"/><rect x="52" y="44" width="4" height="4"/><rect x="60" y="40" width="4" height="4"/><rect x="48" y="52" width="4" height="4"/><rect x="40" y="60" width="4" height="4"/><rect x="56" y="56" width="6" height="6"/><rect x="72" y="40" width="4" height="4"/><rect x="84" y="48" width="4" height="4"/><rect x="44" y="84" width="4" height="4"/><rect x="72" y="72" width="4" height="4"/><rect x="84" y="84" width="4" height="4"/></g></svg></div>
          <div><div class="cta-t">Pay your balance</div><div class="cta-d">{{ $terms === 'due_now' ? 'Scan or tap to pay now — secured by Stripe.' : 'Pay anytime before pickup — secured by Stripe.' }}</div><div class="cta-u">Pay link in your emailed receipt</div></div>
        </div>
      @endif

      <div class="tot">
        <div class="tr"><span>Subtotal</span><span class="num">{{ format_money($subtotal) }}</span></div>
        <div class="tr"><span>Tax</span><span class="num">{{ format_money($tax) }}</span></div>
        @if(!$isPaid && $paid > 0)<div class="tr disc"><span>Deposit paid</span><span class="num">&minus;{{ format_money($paid) }}</span></div>@endif
        <div class="grand"><div class="gl">{{ $isPaid ? 'Total' : 'Balance' }}<small>{{ $isPaid ? 'paid' : ($terms === 'due_now' ? 'due now' : 'due on completion') }}</small></div><div class="gv num">{{ format_money($isPaid ? $total : $balance) }}</div></div>
      </div>
    </div>
  </div>

  <div class="terms"><div class="tx">Service warrantied 30 days against defects; parts carry manufacturer warranty only.</div><div class="bl">{{ $tenant->name }}<small>{{ $tenant->phone }}</small></div></div>
</body>
</html>
