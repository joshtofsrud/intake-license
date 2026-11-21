{{-- MARKER-SITE-HEALTH: one row's details. $r is a row from SiteHealthView::data(). --}}
@php
  $d = $r['detail'];
  $why = [
      'title_long' => 'The end of a long title is cut off in search, often losing the part that says where you are.',
      'title_same' => 'Pages that share a title compete with each other, and searchers cannot tell them apart.',
      'desc_missing' => 'Search engines show a description under the page title. Without one they pick a line from the page.',
      'desc_short' => 'A short description leaves room unused that could say who the page is for.',
      'desc_long' => 'The end of a long description is cut off in search.',
      'desc_same' => 'The same description on several pages tells a searcher nothing about this one.',
      'items_no_photo' => 'Items without a photo are skipped by shoppers and by Google\'s product listings.',
      'items_few_words' => 'Item pages with almost no words rarely show in search. A few sentences on who it is for is enough.',
      'dead_links' => 'A dead link is a dead end for a customer and a mark against the page.',
      'page_error' => 'Visitors and search engines both get an error instead of this page.',
      'page_unreachable' => 'The page took too long or did not answer. If it opens fine for you, check again.',
      'phone_differs' => 'Search engines match your details across the web. Two numbers reads as two businesses.',
      'site_hidden' => 'While this is so, nothing on your site can show in search.',
      'no_phone' => 'Local search shows a phone number first. Without one your listing has less to show.',
      'no_address' => 'Local search needs to know where you are to show you to people nearby.',
      'no_logo' => 'Your logo is the picture shown beside your name in search and when a link is shared.',
  ][$r['code']] ?? null;
  $crumb = $r['path'] !== null && trim($r['path'], '/') !== '' ? str_replace('/', ' › ', trim($r['path'], '/')) : 'home';
  $editPage = $r['page'] ? route('tenant.pages.edit', $r['page']->id) : null;
  $shownTitle = (string) ($d['shown'] ?? '');
  $shownDesc = (string) ($d['shown_desc'] ?? '');
@endphp
<h3>
  <span class="sh-h">{{ $r['problem'] }}@if ($why)<button type="button" class="ia-tip" aria-label="Why it matters">i</button><span class="ia-tip-text" hidden>{{ $why }}</span>@endif</span>
  <span class="sh-h-r">{{ $r['label'] }}<button type="button" class="sh-x" data-sh-shut aria-label="Close">&times;</button></span>
</h3>
<div class="sd-tags"><span>{{ ucfirst($r['impact']) }} impact</span>@if ($r['suggest'])<span>Suggestion ready</span>@endif @if ($r['status'] === 'fixed')<span>Fixed</span>@elseif ($r['status'] === 'hidden')<span>Set aside</span>@endif</div>

@if ($r['status'] === 'fixed')
  <p class="sh-note">Saved and live on your site. The next check confirms it.</p>
  <div class="sd-act">
    @if ($r['can_undo'])<button type="button" class="ia-btn ia-btn--sm" data-sh-post="{{ route('tenant.site_health.undo', $r['id']) }}">Undo</button>@endif
    @if ($editPage)<a class="ia-btn ia-btn--sm" href="{{ $editPage }}">Open the page editor</a>@endif
  </div>

@elseif ($r['status'] === 'hidden')
  <p class="sh-note">Set aside. It no longer counts against your score.</p>
  <div class="sd-act"><button type="button" class="ia-btn ia-btn--sm" data-sh-post="{{ route('tenant.site_health.restore', $r['id']) }}">Put back on the list</button></div>

@elseif ($r['field'])
  {{-- a title or description the shop can word right here, with how it will look in search --}}
  @php $isTitle = $r['field'] === 'title'; $start = $r['suggest'] ?? $r['now']; @endphp
  <form class="sh-edit" data-sh-save="{{ route('tenant.site_health.save', $r['id']) }}" data-field="{{ $r['field'] }}" data-suffix="{{ $sh['suffix'] }}" data-max="{{ $isTitle ? \App\Services\SiteHealth\SiteChecker::TITLE_MAX : \App\Services\SiteHealth\SiteChecker::DESC_MAX }}">
    <div class="sh-cmp">
      <div>
        <small>In search now</small>
        <div class="sh-snip bad"><div class="u"><i></i><span>{{ $sh['shop'] }}<small>{{ $sh['host'] }} › {{ $crumb }}</small></span></div><div class="t">{{ $shownTitle }}</div><div class="d">{{ $shownDesc !== '' ? $shownDesc : 'Search picks its own line from the page.' }}</div></div>
        @foreach (($d['same'] ?? []) as $o)
          <div class="sh-snip bad mini"><div class="u"><span><small>{{ $sh['host'] }} › {{ trim($o['path'], '/') !== '' ? str_replace('/', ' › ', trim($o['path'], '/')) : 'home' }}</small></span></div><div class="t">{{ $o['title'] }}</div></div>
        @endforeach
      </div>
      <div>
        <small>With your wording</small>
        <div class="sh-snip"><div class="u"><i></i><span>{{ $sh['shop'] }}<small>{{ $sh['host'] }} › {{ $crumb }}</small></span></div><div class="t" data-sh-t>{{ $isTitle ? $start . $sh['suffix'] : $shownTitle }}</div><div class="d" data-sh-d>{{ $isTitle ? ($shownDesc !== '' ? $shownDesc : 'Search picks its own line from the page.') : ($start !== '' ? $start : 'Start typing to see it here.') }}</div></div>
      </div>
    </div>
    <label class="sh-lab" for="shF{{ $r['id'] }}">{{ $isTitle ? 'Title' : 'Description' }} <span class="sh-count" data-sh-count></span></label>
    @if ($isTitle)
      <input class="ia-input" id="shF{{ $r['id'] }}" name="value" type="text" maxlength="120" value="{{ $start }}" autocomplete="off">
    @else
      <textarea class="ia-textarea" id="shF{{ $r['id'] }}" name="value" rows="3" maxlength="320" placeholder="Who this page is for and what they can do here, in a sentence or two.">{{ $start }}</textarea>
    @endif
    <div class="sd-act">
      <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Save</button>
      @if ($editPage)<a class="ia-btn ia-btn--sm" href="{{ $editPage }}">Open the page editor</a>@endif
      <button type="button" class="ia-btn ia-btn--sm" data-sh-post="{{ route('tenant.site_health.hide', $r['id']) }}">Not a problem</button>
    </div>
  </form>

@else
  @if ($r['code'] === 'items_no_photo')
    <div class="sh-phs" data-sh-items="photo">
      @foreach (($d['items'] ?? []) as $it)
        <a class="sh-ph" href="{{ route('tenant.inventory.edit', $it['id']) }}"><span></span><em>{{ $it['name'] }}</em></a>
      @endforeach
    </div>
    @if (($d['n'] ?? 0) > count($d['items'] ?? []))
      <button type="button" class="sh-more" data-sh-more="{{ route('tenant.site_health.items', $r['id']) }}" data-shown="{{ count($d['items']) }}" data-total="{{ $d['n'] }}">Show more · {{ count($d['items']) }} of {{ number_format($d['n']) }}</button>
    @endif
  @elseif ($r['code'] === 'items_few_words')
    <ul class="sh-items" data-sh-items="words">
      @foreach (($d['items'] ?? []) as $it)
        <li><a href="{{ route('tenant.inventory.edit', $it['id']) }}">{{ $it['name'] }}</a><span>{{ $it['words'] ? $it['words'] . ' ' . \Illuminate\Support\Str::plural('word', $it['words']) : 'no description' }}</span></li>
      @endforeach
    </ul>
    @if (($d['n'] ?? 0) > count($d['items'] ?? []))
      <button type="button" class="sh-more" data-sh-more="{{ route('tenant.site_health.items', $r['id']) }}" data-shown="{{ count($d['items']) }}" data-total="{{ $d['n'] }}">Show more · {{ count($d['items']) }} of {{ number_format($d['n']) }}</button>
    @endif
  @elseif ($r['code'] === 'dead_links')
    <ul class="sh-items">
      @foreach (($d['links'] ?? []) as $l)
        <li><b>{{ $l['text'] !== '' ? $l['text'] : 'A link with no words' }}</b><span>goes to {{ $l['to'] }}</span></li>
      @endforeach
    </ul>
  @elseif ($r['code'] === 'phone_differs')
    <dl class="sd-kv"><dt>On this page</dt><dd>{{ implode(', ', $d['found'] ?? []) }}</dd><dt>Your locations</dt><dd>{{ implode(', ', array_unique($d['yours'] ?? [])) }}</dd></dl>
  @endif

  <div class="sd-act">
    @if (in_array($r['code'], ['items_no_photo', 'items_few_words'], true) && ! empty($d['items']))
      <a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ route('tenant.inventory.edit', $d['items'][0]['id']) }}">Open the first item</a>
    @elseif (in_array($r['code'], ['no_phone', 'no_address'], true))
      <a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ route('tenant.locations.index') }}">Open Locations</a>
    @elseif ($r['code'] === 'no_logo')
      <a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ route('tenant.settings.index') }}">Open Business settings</a>
    @elseif ($r['code'] === 'site_hidden')
      <a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ route('tenant.pages.index') }}">Open Pages</a>
    @elseif ($editPage)
      <a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ $editPage }}">Open the page editor</a>
    @endif
    @if ($r['code'] === 'phone_differs')<a class="ia-btn ia-btn--sm" href="{{ route('tenant.locations.index') }}">Open Locations</a>@endif
    @if ($r['path'] !== null)<a class="ia-btn ia-btn--sm" href="https://{{ $sh['host'] }}{{ $r['path'] }}" target="_blank" rel="noopener">See it on your site</a>@endif
    <button type="button" class="ia-btn ia-btn--sm" data-sh-post="{{ route('tenant.site_health.hide', $r['id']) }}">Not a problem</button>
  </div>
@endif
