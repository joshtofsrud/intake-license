{{-- MARKER-POSTS: one post inside the site chrome --}}
@include('public.sections._post_styles')
<article class="p-section">
  <div class="p-container" style="max-width:760px">
    <a href="/posts?feed={{ urlencode($post->feed) }}" style="font-size:13px;opacity:.6;text-transform:uppercase;letter-spacing:.06em;font-weight:600">{{ $post->feed }}</a>
    <h1 class="p-section-heading" style="margin-top:8px">{{ $post->title }}</h1>
    <div style="font-size:14px;opacity:.6">{{ tlocal($post->published_at, 'l, F j, Y') }}</div>
    @if($post->image_url)
      <img src="{{ $post->image_url }}" alt="" style="width:100%;border-radius:var(--p-r-lg,12px);margin:26px 0 8px">
    @endif
    <div style="margin-top:22px;font-size:17px;line-height:1.75">
      @foreach($post->paragraphs() as $para)
        <p style="margin:0 0 1.1em">{!! nl2br(e($para)) !!}</p>
      @endforeach
    </div>
    @if($more->isNotEmpty())
      <h2 style="font-size:20px;margin:44px 0 6px">More {{ strtolower($post->feed) }}</h2>
      <div class="pst-list">
        @foreach($more as $p)
          @include('public.sections._post_card', ['p' => $p])
        @endforeach
      </div>
    @endif
  </div>
</article>
