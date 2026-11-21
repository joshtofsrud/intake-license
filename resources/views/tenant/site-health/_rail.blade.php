{{-- MARKER-SITE-HEALTH: the basics, then Google's numbers. Each Google panel stands alone:
     its own numbers, its own "pick which one", its own error. --}}
@php
  $g = $sh['google'];
  $ok = collect($sh['basics'])->where('ok', true)->count();
@endphp

@if ($sh['basics'])
  <div class="sh-basics {{ $ok === count($sh['basics']) ? '' : 'part' }}">
    <i>{{ $ok === count($sh['basics']) ? '✓' : '!' }}</i>
    <b>{{ $ok }} of {{ count($sh['basics']) }} basics in place</b>
    <button type="button" class="ia-tip" aria-label="Which basics">i</button><span class="ia-tip-text" hidden>@foreach ($sh['basics'] as $b){{ $b['ok'] ? '✓ ' . $b['yes'] : '✗ ' . $b['no'] }}<br>@endforeach</span>
  </div>
@endif

@if ($g['available'] ?? false)
  <div class="sd-tl-h" id="shGoogle">
    <b>From Google</b>
    @if ($g['connected'])
      <span class="sh-gmeta">
        @if ($g['running'])<span class="sh-dot"></span>Reading…@else{{ $g['synced'] ? 'Read ' . $g['synced'] : 'Not read yet' }} · <button type="button" class="sh-link" id="shRefresh">Refresh</button>@endif
      </span>
    @else
      <span>one sign-in</span>
    @endif
  </div>
  @if ($g['error'])<p class="sh-err">{{ $g['error'] }}</p>@endif

  @foreach ($g['sources'] as $src => $s)
    @php $data = $s['data']; @endphp
    <div class="sh-lock" @if ($src === 'gbp') id="reviews" @endif>
      <div class="sh-lock-h">
        <div><b>{{ $s['name'] }}</b><span class="sh-free">Free</span><button type="button" class="ia-tip" aria-label="What this shows">i</button><span class="ia-tip-text" hidden>{{ $s['tip'] }}</span></div>
        @if (! $g['connected'])
          <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" data-sh-connect>Connect Google</button>
        @elseif ($s['reconnect'])
          <button type="button" class="ia-btn ia-btn--sm" data-sh-connect>Connect again</button>
        @elseif ($data)
          <span class="sh-conn">✓ Connected</span>
        @endif
      </div>

      @if ($g['connected'])
        @if ($s['needs_pick'])
          <form class="sh-pick" data-sh-pick="{{ $src }}">
            <select class="ia-select" name="id" aria-label="Which one to read for {{ $s['name'] }}">
              <option value="">Pick which one to read</option>
              @foreach ($s['options'] as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }}</option>@endforeach
            </select>
            <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Save</button>
          </form>
        @endif

        @if ($s['error'])
          <p class="sh-err">{{ $s['error'] }}</p>
        @elseif (! $data && ! $s['needs_pick'])
          <p class="sh-cap">{{ $g['running'] ? 'Reading from Google…' : 'Nothing read yet. Press Refresh.' }}</p>
        @elseif ($data && $src === 'gsc')
          <div class="sh-on">
            @forelse ($data['queries'] as $q)
              <div class="sh-q"><span>{{ $q['q'] }}</span><b>{{ $q['pos'] }}</b><em class="{{ $q['move'] > 0 ? 'up' : ($q['move'] < 0 ? 'dn' : '') }}">{{ $q['move'] === null ? 'new' : ($q['move'] > 0 ? '▲' . $q['move'] : ($q['move'] < 0 ? '▼' . abs($q['move']) : '—')) }}</em></div>
            @empty
              <p class="sh-cap">No searches led to your site in the last 28 days yet.</p>
            @endforelse
            @if ($data['queries'])<div class="sh-cap">Position in search, last 28 days</div>@endif
          </div>
        @elseif ($data && $src === 'ga')
          @php $mx = max(1, collect($data['channels'])->max('n') ?? 1); @endphp
          <div class="sh-on">
            @if ($data['channels'])
              <div class="sh-bars">
                @foreach ($data['channels'] as $c)<span title="{{ $c['name'] }}: {{ number_format($c['n']) }}"><i style="height:{{ max(3, round($c['n'] / $mx * 100)) }}%"></i><small>{{ \Illuminate\Support\Str::limit(str_replace('Organic ', '', $c['name']), 9, '') }}</small></span>@endforeach
              </div>
            @endif
            <div class="sh-cap">{{ number_format($data['sessions']) }} visits, last 28 days</div>
          </div>
        @elseif ($data && $src === 'mc')
          @php $c = $data['counts']; $sum = max(1, $c['showing'] + $c['limited'] + $c['rejected']); @endphp
          <div class="sh-on">
            <div class="sh-stack">
              @foreach (['showing' => 'g1', 'limited' => 'g2', 'rejected' => 'g3'] as $k => $cls)
                @if ($c[$k] > 0)<span class="{{ $cls }}" style="width:{{ max(8, round($c[$k] / $sum * 100)) }}%">{{ number_format($c[$k]) }}</span>@endif
              @endforeach
            </div>
            <div class="sh-key"><span><i class="g1"></i>Showing in Google</span><span><i class="g2"></i>Limited</span><span><i class="g3"></i>Rejected</span></div>
            @foreach ($data['items'] as $it)
              <div class="sh-item"><div class="th"></div><span><b>{{ $it['title'] }}</b><small>{{ $it['state'] === 'rejected' ? 'Rejected' : 'Limited' }}{{ $it['why'] ? ': ' . $it['why'] : '' }}</small></span>
                @if ($it['item'] ?? null)<a href="{{ route('tenant.inventory.edit', $it['item']) }}">Fix</a>@else<a href="{{ route('tenant.storefront.settings') }}">Feed</a>@endif
              </div>
            @endforeach
            @if ($c['pending'] > 0)<div class="sh-cap">{{ number_format($c['pending']) }} more still being reviewed by Google</div>@endif
          </div>
        @elseif ($data && $src === 'gbp')
          <div class="sh-on">
            <div class="sh-rate"><b>{{ $data['rating'] !== null ? number_format($data['rating'], 1) : '–' }}</b><div><span class="sh-stars" style="--n:{{ (float) $data['rating'] }}" aria-label="{{ $data['rating'] }} stars"></span><small>{{ number_format($data['total']) }} {{ \Illuminate\Support\Str::plural('review', $data['total']) }}{{ $data['new'] ? ' · ' . $data['new'] . ' new this month' : '' }}</small></div></div>
            <div class="sh-dist" title="Your latest {{ $data['dist_of'] }} reviews">
              @foreach ($data['dist'] as $star => $n)<span><small>{{ $star }}</small><i><u style="width:{{ round($n / max(1, $data['dist_of']) * 100) }}%"></u></i></span>@endforeach
            </div>
            @forelse ($data['reviews'] as $rv)
              <div class="sh-rev">
                <div class="h"><b>{{ $rv['who'] }}</b><span class="sh-stars" style="--n:{{ $rv['stars'] }}" aria-label="{{ $rv['stars'] }} stars"></span><small>{{ \App\Support\SiteHealthView::ago($rv['at']) }}</small></div>
                @if ($rv['text'] !== '')<p>{{ $rv['text'] }}</p>@endif
                @if ($rv['reply'])
                  <div class="f ok"><span>✓ Replied</span></div>
                @else
                  <div class="f"><span>Needs a reply</span><button type="button" class="sh-link" data-sh-reply-open>Write a reply</button></div>
                  <form class="sh-draft" data-sh-reply="{{ $rv['name'] }}" hidden>
                    <textarea class="ia-textarea" name="text" rows="3" maxlength="4000" placeholder="Your reply. Everyone on Google can read it." aria-label="Your reply to {{ $rv['who'] }}"></textarea>
                    <div><button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Post reply</button><button type="button" class="ia-btn ia-btn--sm" data-sh-reply-open>Cancel</button></div>
                  </form>
                @endif
              </div>
            @empty
              <p class="sh-cap">No reviews yet.</p>
            @endforelse
          </div>
        @endif
        @if (! $s['needs_pick'] && count($s['options']) > 1 && $data)
          <details class="sh-change">
            <summary>Reading {{ collect($s['options'])->firstWhere('id', $s['pick'])['name'] ?? 'one of ' . count($s['options']) }} · <u>change</u></summary>
            <form class="sh-pick" data-sh-pick="{{ $src }}">
              <select class="ia-select" name="id" aria-label="Which one to read for {{ $s['name'] }}">
                @foreach ($s['options'] as $o)<option value="{{ $o['id'] }}" @selected($s['pick'] === $o['id'])>{{ $o['name'] }}</option>@endforeach
              </select>
              <button type="submit" class="ia-btn ia-btn--sm">Save</button>
            </form>
          </details>
        @endif
      @endif
    </div>
  @endforeach

  @if ($g['connected'])
    <form method="POST" action="{{ route('tenant.site_health.google.disconnect') }}" class="sh-off">
      @csrf
      <span>{{ $g['email'] ?: 'Google connected' }}</span>
      <button type="submit" class="sh-link" data-confirm="Disconnect Google? Intake forgets the sign-in and the numbers it read. Nothing in your Google account changes.">Disconnect</button>
    </form>
  @endif
@endif
