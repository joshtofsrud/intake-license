{{-- MARKER-PATCH-348 — Branded graphical invoice. Table-based for dompdf
     (no flex/grid/svg/shadow). Dark header band with embedded logo (base64,
     since dompdf has remote images disabled) and a wordmark fallback; asset
     work-cards with a parts & products rail; dark totals panel. Logo height is
     driven by $logoSize (small/medium/large/xl -> 60/76/96/120px), set from the
     Print & Send window. Inter embedded locally; DejaVu Sans Mono (bundled with
     dompdf) carries the data type. --}}
@php
  $isPaid   = $terms === 'paid';
  $acc      = $tenant->accent_color ?: '#BEF264';
  $interDir = public_path('fonts/inter');
  $hasInter = is_file($interDir . '/Inter-Regular.ttf');

  $logoPx = ['small' => 60, 'medium' => 76, 'large' => 96, 'xl' => 120][$logoSize ?? 'medium'] ?? 76;
  $logo   = $logoData ?? null;          // data: URI prepared by the controller, or null
  $markFs = (int) round($logoPx * 0.42);

  // wordmark fallback: up to two initials from the shop name
  $words = preg_split('/\s+/', trim((string) $tenant->name)) ?: [];
  $ini   = strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)))) ?: 'GC';
  $addr1 = trim(strtok((string) $tenant->address, "\n"));
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @if($hasInter)
  @font-face { font-family:'Inter'; font-weight:400; src:url("{{ $interDir }}/Inter-Regular.ttf")  format("truetype"); }
  @font-face { font-family:'Inter'; font-weight:500; src:url("{{ $interDir }}/Inter-Medium.ttf")   format("truetype"); }
  @font-face { font-family:'Inter'; font-weight:600; src:url("{{ $interDir }}/Inter-SemiBold.ttf") format("truetype"); }
  @font-face { font-family:'Inter'; font-weight:700; src:url("{{ $interDir }}/Inter-Bold.ttf")     format("truetype"); }
  @endif
  * { margin:0; padding:0; box-sizing:border-box; }
  @page { margin:0; }
  body { font-family:{{ $hasInter ? "'Inter'," : '' }} 'DejaVu Sans', sans-serif; color:#16160f; font-size:13px; line-height:1.5; }
  .mono { font-family:'DejaVu Sans Mono', monospace; }
  table { width:100%; border-collapse:collapse; }
  .pad { padding:0 46px; }

  /* header band */
  .band { background:#16160f; padding:30px 46px 26px; }
  .band td { vertical-align:middle; }
  .mark { display:inline-block; width:{{ $logoPx }}px; height:{{ $logoPx }}px; background:{{ $acc }};
    border-radius:14px; color:#16160f; font-family:'DejaVu Sans Mono', monospace; font-weight:700;
    font-size:{{ $markFs }}px; text-align:center; line-height:{{ $logoPx }}px; }
  .wm { display:inline-block; vertical-align:middle; padding-left:16px; }
  .wm .n { font-size:22px; font-weight:700; letter-spacing:-.4px; color:#fff; line-height:1; }
  .wm .t { font-size:10px; letter-spacing:.5px; color:#9b9b90; margin-top:5px; text-transform:uppercase; }
  .logoimg { height:{{ $logoPx }}px; width:auto; }
  .inv { text-align:right; }
  .inv .word { font-size:30px; font-weight:700; letter-spacing:4px; color:#fff; line-height:1; }
  .meta { margin-top:12px; font-size:10.5px; color:#b7b7ad; line-height:1.85; }
  .meta b { color:#fff; font-weight:500; }
  .pill { display:inline-block; background:{{ $acc }}; color:#16160f; font-size:9px; font-weight:700;
    letter-spacing:.8px; text-transform:uppercase; padding:3px 9px; border-radius:20px; margin-top:7px; }
  .rule { height:3px; background:{{ $acc }}; }

  .eyebrow { font-size:9.5px; letter-spacing:1.2px; text-transform:uppercase; color:#9a9a90; }
  /* parties */
  .parties { margin:26px 0 4px; }
  .parties td { vertical-align:top; width:50%; padding-right:24px; }
  .party .nm { font-size:14.5px; font-weight:700; padding-top:5px; }
  .party .sub { font-size:12px; color:#6f6f66; padding-top:2px; }

  /* asset cards */
  .card { border:1px solid #e7e7e0; border-radius:12px; margin:16px 0; page-break-inside:avoid; }
  .card-h { background:#fafaf7; border-bottom:1px solid #eeeee7; border-radius:12px 12px 0 0; }
  .card-h td { padding:13px 18px; vertical-align:middle; }
  .card-h .tick { width:8px; }
  .card-h .tick i { display:inline-block; width:8px; height:8px; border-radius:8px; background:{{ $acc }}; }
  .card-h .an { font-size:15px; font-weight:700; letter-spacing:-.2px; padding-left:10px; }
  .card-h .av { text-align:right; white-space:nowrap; }
  .card-h .av .l { font-size:9px; letter-spacing:1px; color:#a3a39a; text-transform:uppercase; }
  .card-h .av .a { font-size:14px; font-weight:700; padding-left:8px; }
  .lines td { padding:9px 18px; font-size:13px; vertical-align:top; }
  .lines td.v { text-align:right; white-space:nowrap; font-weight:600; }
  .lines tr.svc td { border-bottom:1px solid #f4f4ee; }
  .lines tr.svc .nm { font-weight:500; }
  .lines tr.add .nm { font-weight:400; color:#555; padding-left:16px; font-size:12.5px; }
  .lines tr.add td.v { font-weight:400; color:#555; }
  .prail td { padding:12px 18px 4px; }
  .prail span { font-size:9px; letter-spacing:1.2px; text-transform:uppercase; color:#a3a39a; }
  .lines tr.part .nm { font-weight:600; }
  .chip { display:inline-block; background:#f3f7e6; color:#5f7320; border:1px solid #dcebb5; font-size:8px;
    font-weight:700; letter-spacing:.6px; text-transform:uppercase; padding:1px 6px; border-radius:4px; margin-left:8px; }
  .qty { color:#9a9a90; font-weight:400; font-size:11.5px; }
  .sku { font-size:10px; color:#b3b3a9; margin-top:2px; }
  .card-f { background:#fafaf7; border-top:1px solid #eeeee7; border-radius:0 0 12px 12px; }
  .card-f td { padding:10px 18px; font-size:12px; color:#6f6f66; }
  .card-f td.v { text-align:right; }
  .card-f td.v b { color:#16160f; font-size:13.5px; padding-left:6px; }

  /* note */
  .note { border:1px solid #e9efd6; border-left:3px solid {{ $acc }}; background:#fbfdf4; border-radius:0 8px 8px 0;
    padding:12px 16px; margin:22px 0; }
  .note .nl { font-size:9.5px; letter-spacing:1px; text-transform:uppercase; color:#8a9a55; padding-bottom:4px; }
  .note .nt { font-size:12.5px; color:#4a4a42; line-height:1.55; }

  /* footer: payment + totals */
  .foot td { vertical-align:top; }
  .foot .pay { width:50%; padding:6px 30px 0 0; }
  .pay .pln { font-size:12.5px; color:#555; line-height:1.6; padding-top:7px; }
  .pay .pln b { color:#16160f; }
  .panel { background:#16160f; border-radius:12px; padding:16px 20px; }
  .panel td { font-size:12.5px; padding:4px 0; color:#b7b7ad; }
  .panel td.v { text-align:right; color:#fff; }
  .panel .div td { border-top:1px solid #2c2c24; height:10px; padding:0; }
  .panel .bal td { padding-top:8px; }
  .panel .bal .k { color:#fff; font-weight:700; font-size:14px; }
  .panel .bal .v { color:{{ $acc }}; font-weight:700; font-size:23px; letter-spacing:-.5px; }
  .panel .stat { display:inline-block; font-size:9px; letter-spacing:1px; text-transform:uppercase; color:#16160f;
    background:{{ $acc }}; border-radius:20px; padding:3px 9px; margin-top:6px; }

  .terms { margin-top:30px; padding:14px 0 30px; border-top:1px solid #efefe8; font-size:10.5px;
    color:#9a9a90; line-height:1.7; text-align:center; }
  .terms .biz { color:#6f6f66; }
</style>
</head>
<body>

  {{-- header band --}}
  <div class="band">
    <table>
      <tr>
        <td>
          @if($logo)
            <img class="logoimg" src="{{ $logo }}" alt="{{ $tenant->name }}">
          @else
            <span class="mark">{{ $ini }}</span><span class="wm">
              <span class="n">{{ $tenant->name }}</span>
              @if($addr1)<div class="t mono">{{ $addr1 }}</div>@endif
            </span>
          @endif
        </td>
        <td class="inv">
          <div class="word">INVOICE</div>
          <div class="meta mono">
            WORK ORDER&nbsp; <b>{{ $number }}</b><br>
            ISSUED&nbsp; <b>{{ now()->format('M j, Y') }}</b><br>
            <span class="pill">{{ $isPaid ? 'Paid in full' : 'Balance due' }}</span>
          </div>
        </td>
      </tr>
    </table>
  </div>
  <div class="rule"></div>

  <div class="pad">

    {{-- bill to / service --}}
    <table class="parties">
      <tr>
        <td>
          <div class="eyebrow">Bill to</div>
          <div class="nm">{{ $customer['name'] ?: 'Customer' }}</div>
          <div class="sub">{{ $customer['email'] }}@if($customer['email'] && $customer['phone']) · @endif{{ $customer['phone'] }}</div>
        </td>
        <td>
          <div class="eyebrow">Service</div>
          <div class="nm">{{ count($assets) }} {{ \Illuminate\Support\Str::plural('asset', count($assets)) }}</div>
          <div class="sub">Completed {{ now()->format('M j, Y') }}</div>
        </td>
      </tr>
    </table>

    {{-- asset work-cards --}}
    @foreach($assets as $a)
      @php
        $svcLines  = array_values(array_filter($a['lines'], fn ($l) => ($l['kind'] ?? 'service') !== 'part'));
        $partLines = array_values(array_filter($a['lines'], fn ($l) => ($l['kind'] ?? '') === 'part'));
      @endphp
      <div class="card">
        <table class="card-h"><tr>
          <td class="tick"><i></i></td>
          <td class="an">{{ $a['name'] }}</td>
          <td class="av"><span class="l">Asset</span><span class="a">{{ format_money($a['subtotal']) }}</span></td>
        </tr></table>
        @if(count($svcLines))
          <table class="lines">
            @foreach($svcLines as $l)
              <tr class="{{ $l['add'] ? 'add' : 'svc' }}"><td class="nm">{{ $l['name'] }}</td><td class="v">{{ format_money($l['cents']) }}</td></tr>
            @endforeach
          </table>
        @endif
        @if(count($partLines))
          <table class="lines">
            <tr class="prail"><td colspan="2"><span>Parts &amp; products</span></td></tr>
            @foreach($partLines as $l)
              <tr class="part">
                <td class="nm">{{ $l['name'] }}@if(!empty($l['custom'])) <span class="chip">Custom</span>@endif
                  @if(($l['qty'] ?? 1) > 1)<span class="qty">× {{ $l['qty'] }}</span>@endif
                  @if(!empty($l['sku']))<div class="sku mono">{{ $l['sku'] }}</div>@endif</td>
                <td class="v">{{ format_money($l['cents']) }}</td>
              </tr>
            @endforeach
          </table>
        @endif
        <table class="card-f"><tr><td>Asset subtotal</td><td class="v"><b>{{ format_money($a['subtotal']) }}</b></td></tr></table>
      </div>
    @endforeach

    {{-- loose / shop items --}}
    @if(count($loose))
      <div class="card">
        <table class="card-h"><tr>
          <td class="tick"><i></i></td>
          <td class="an">Shop &amp; parts</td>
          <td class="av"></td>
        </tr></table>
        <table class="lines">
          @foreach($loose as $l)
            <tr class="{{ ($l['kind'] ?? '') === 'part' ? 'part' : ($l['add'] ? 'add' : 'svc') }}">
              <td class="nm">{{ $l['name'] }}@if(!empty($l['custom'])) <span class="chip">Custom</span>@endif
                @if(($l['kind'] ?? '') === 'part' && ($l['qty'] ?? 1) > 1)<span class="qty">× {{ $l['qty'] }}</span>@endif
                @if(!empty($l['sku']))<div class="sku mono">{{ $l['sku'] }}</div>@endif</td>
              <td class="v">{{ format_money($l['cents']) }}</td>
            </tr>
          @endforeach
        </table>
      </div>
    @endif

    {{-- shop note --}}
    @if(trim((string) $note) !== '')
      <div class="note">
        <div class="nl">Note from the shop</div>
        <div class="nt">{!! nl2br(e($note)) !!}</div>
      </div>
    @endif

    {{-- payment + totals --}}
    <table class="foot">
      <tr>
        <td class="pay">
          <div class="eyebrow">Payment</div>
          @if($isPaid)
            <div class="pln">Paid in full. Thank you — no balance remaining.</div>
          @else
            @if($paid > 0)<div class="pln">Deposit paid — <b>{{ format_money($paid) }}</b>.</div>@endif
            <div class="pln">{{ $terms === 'due_now' ? 'Balance due now.' : 'Balance due on completion, or pay online — link in your emailed receipt.' }}</div>
          @endif
          @if($tenant->phone)<div class="pln">Questions? <b>{{ $tenant->phone }}</b></div>@endif
        </td>
        <td>
          <div class="panel">
            <table>
              <tr><td>Subtotal</td><td class="v">{{ format_money($subtotal) }}</td></tr>
              {{-- MARKER-DOC-DISCOUNT --}}
              @if((int) ($discount ?? 0) > 0)
              <tr><td>{{ !empty($discount_code) ? 'Discount (' . $discount_code . ')' : 'Discount' }}</td><td class="v">&minus;{{ format_money($discount) }}</td></tr>
              @endif
              <tr><td>Tax</td><td class="v">{{ format_money($tax) }}</td></tr>
              @if(!$isPaid && $paid > 0)<tr><td>Deposit paid</td><td class="v">&minus;{{ format_money($paid) }}</td></tr>@endif
              <tr class="div"><td colspan="2"></td></tr>
              <tr class="bal">
                <td class="k">{{ $isPaid ? 'Total paid' : 'Balance due' }}</td>
                <td class="v">{{ format_money($isPaid ? $total : $balance) }}</td>
              </tr>
              <tr><td colspan="2"><span class="stat">{{ $isPaid ? 'Paid' : 'Awaiting payment' }}</span></td></tr>
            </table>
          </div>
        </td>
      </tr>
    </table>

    <div class="terms">
      @if(trim((string) ($tenant->invoice_footer_terms ?? '')) !== ''){!! nl2br(e($tenant->invoice_footer_terms)) !!}<br>@endif
      <span class="biz mono">{{ $tenant->name }}@if($tenant->email) · {{ $tenant->email }}@endif</span>
    </div>

  </div>
</body>
</html>
