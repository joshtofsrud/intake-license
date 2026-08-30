{{-- MARKER-INVEST-V2 — terms, progress and documents. Included by BOTH the
     gated page and the investor portal, so the two can never quote different
     numbers at the same person. Expects: $target $cap $equity $instrument
     $funded $committed $showBar $docs $docUrl (closure taking a slug). --}}

<section class="hero" id="terms"><div class="wrap">
  <span class="eyebrow">Investment opportunity</span>
  {{-- MARKER-INVEST-ACRONYM — lcfirst so it reads mid-sentence without
       flattening SAFE into safe. --}}
  <h1>${{ number_format($target) }} on a {{ lcfirst($instrument) }}
      at a ${{ rtrim(rtrim(number_format($cap / 1000000, 1), '0'), '.') }}M cap.</h1>
  <p class="lede">{{ $equity }}% on conversion. The same terms apply to every participant — no side
    letter, and no better price for going first. Everything here is a summary; the proposal is the
    document.</p>

  @if($showBar && ($funded || $committed))
    @php
      $pctFunded = $target > 0 ? min(100, $funded / $target * 100) : 0;
      $pctOpen   = $target > 0 ? min(100 - $pctFunded, max(0, $committed - $funded) / $target * 100) : 0;
    @endphp
    <div class="prog">
      <div class="progtop">
        <span class="big">${{ number_format($committed) }}</span>
        <span class="of">of ${{ number_format($target) }} spoken for</span>
      </div>
      <div class="bar">
        <i class="b1" style="width:{{ $pctFunded }}%"></i>
        <i class="b2" style="width:{{ $pctOpen }}%"></i>
      </div>
      <div class="key">
        <span><i class="k1"></i> ${{ number_format($funded) }} signed and funded</span>
        <span><i class="k2"></i> ${{ number_format(max(0, $committed - $funded)) }} committed, not yet funded</span>
        {{-- MARKER-RAISE-HTML — past the target is a real state, not an error.
             The round closes when it is closed, not when a number is reached. --}}
        @if($committed > $target)
          <span><i class="k2"></i> ${{ number_format($committed - $target) }} above target</span>
        @else
          <span><i class="k3"></i> ${{ number_format($target - $committed) }} open</span>
        @endif
      </div>
    </div>
  @endif
</div></section>

@if($docs)
<section id="docs"><div class="wrap">
  <p class="sub">Read first</p>
  <div class="docs">
    @foreach($docs as $doc)
      <a class="doc" href="{{ $docUrl($doc['slug']) }}">
        <b>{{ $doc['label'] }} &rarr;</b><span>{{ $doc['meta'] }}</span>
      </a>
    @endforeach
  </div>
  <p class="fine">Streamed to you, not linked publicly — these URLs won't work for anyone else, and stop
    working if your link is withdrawn. The proposal carries the problem, the model, the market, the use
    of funds and the risks; this page deliberately doesn't repeat them.</p>
</div></section>
@endif
