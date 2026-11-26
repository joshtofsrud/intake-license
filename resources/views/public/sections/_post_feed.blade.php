{{-- MARKER-POSTS: builder section — the latest posts from one feed (or all) --}}
@php
  $pfTenant = $tenant ?? $currentTenant ?? null;
  $pfFeed   = trim((string) ($c['feed'] ?? ''));
  $pfMax    = max(1, min(12, (int) ($c['max_posts'] ?? 3)));
  $pfPosts  = $pfTenant ? \App\Models\Tenant\TenantPost::where('tenant_id', $pfTenant->id)->live()
      ->when($pfFeed !== '', fn ($q) => $q->where('feed', $pfFeed))
      ->orderByDesc('published_at')->limit($pfMax)->get() : collect();
  $pfCards  = ($c['layout'] ?? 'list') === 'cards';
  $pfAlign  = ($c['text_align'] ?? 'left') === 'center' ? 'center' : 'left';
  $pfStyle  = '';
  if (($c['bg_mode'] ?? 'none') === 'gradient' && ! empty($c['bg_gradient_from'])) {
      $pfStyle = 'background:linear-gradient(' . (int) ($c['bg_gradient_angle'] ?? 135) . 'deg,' . e($c['bg_gradient_from']) . ',' . e($c['bg_gradient_to'] ?: $c['bg_gradient_from']) . ');';
  } elseif (! empty($c['bg_color']) && $c['bg_color'] !== 'inherit') {
      $pfStyle = 'background:' . e($c['bg_color']) . ';';
  }
  if (! empty($c['text_color'])) $pfStyle .= 'color:' . e($c['text_color']) . ';';
  $pfAnchor = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
  $pfAll    = '/posts' . ($pfFeed !== '' ? '?feed=' . urlencode($pfFeed) : '');
@endphp
@include('public.sections._post_styles')
<section class="p-section {{ trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($c['custom_classes'] ?? ''))) }}" @if($pfAnchor) id="{{ $pfAnchor }}" @endif @if($pfStyle) style="{{ $pfStyle }}" @endif>
  <div class="p-container">
    @if(! empty($c['eyebrow']) || ! empty($c['heading']) || ! empty($c['body']))
      <div style="text-align:{{ $pfAlign }};margin-bottom:22px">
        @if(! empty($c['eyebrow']))<div class="p-eyebrow">{{ $c['eyebrow'] }}</div>@endif
        @if(! empty($c['heading']))<h2 class="p-section-heading">{{ $c['heading'] }}</h2>@endif
        @if(! empty($c['body']))<p class="p-section-sub" style="{{ $pfAlign === 'center' ? 'margin:0 auto' : '' }}">{{ $c['body'] }}</p>@endif
      </div>
    @endif
    <div class="pst-list {{ $pfCards ? 'pst-cards' : '' }}">
      @forelse($pfPosts as $p)
        @include('public.sections._post_card', ['p' => $p, 'pcShowDate' => ($c['show_dates'] ?? '1') === '1', 'pcShowImage' => ($c['show_images'] ?? '1') === '1', 'pcShowFeed' => $pfFeed === ''])
      @empty
        <p class="pst-empty">{{ $c['empty_text'] ?? 'Nothing posted yet — check back soon.' }}</p>
      @endforelse
    </div>
    @if(! empty($c['cta_label']) && $pfPosts->isNotEmpty())
      <div style="text-align:{{ $pfAlign }};margin-top:26px"><a href="{{ $c['cta_url'] ?: $pfAll }}" class="p-btn p-btn--primary bfx">{{ $c['cta_label'] }}</a></div>
    @endif
  </div>
</section>
