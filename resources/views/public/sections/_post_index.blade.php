{{-- MARKER-POSTS: /posts inside the site chrome --}}
@include('public.sections._post_styles')
<section class="p-section">
  <div class="p-container" style="max-width:860px">
    <h1 class="p-section-heading">{{ $feed !== '' ? $feed : 'Posts' }}</h1>
    @if(count($feeds) > 1)
      <nav class="pst-chips" aria-label="Feeds">
        <a href="/posts" class="pst-chip {{ $feed === '' ? 'on' : '' }}">All</a>
        @foreach($feeds as $fd)
          <a href="/posts?feed={{ urlencode($fd) }}" class="pst-chip {{ $feed === $fd ? 'on' : '' }}">{{ $fd }}</a>
        @endforeach
      </nav>
    @endif
    <div class="pst-list">
      @forelse($posts as $p)
        @include('public.sections._post_card', ['p' => $p, 'pcShowFeed' => $feed === '' && count($feeds) > 1])
      @empty
        <p class="pst-empty">Nothing posted yet. <a href="/" style="text-decoration:underline">Back to the home page</a></p>
      @endforelse
    </div>
    @if($posts->hasPages())
      <div style="display:flex;justify-content:space-between;margin-top:28px">
        @if($posts->previousPageUrl())<a class="p-btn p-btn--outline p-btn--sm" href="{{ $posts->previousPageUrl() }}">← Newer</a>@else<span></span>@endif
        @if($posts->nextPageUrl())<a class="p-btn p-btn--outline p-btn--sm" href="{{ $posts->nextPageUrl() }}">Older →</a>@endif
      </div>
    @endif
  </div>
</section>
