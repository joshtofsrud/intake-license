{{-- MARKER-POSTS: one post in a list. $p = TenantPost, $pcShowDate, $pcShowImage, $pcShowFeed --}}
<a href="{{ $p->url() }}" class="pst-card">
  @if(($pcShowImage ?? true) && $p->image_url)
    <span class="pst-img" style="background-image:url('{{ e($p->image_url) }}')"></span>
  @endif
  <span class="pst-txt">
    <span class="pst-meta">
      @if($pcShowDate ?? true)<time datetime="{{ $p->published_at?->toIso8601String() }}">{{ $p->published_at ? tlocal($p->published_at, 'M j, Y') : '' }}</time>@endif
      @if(($pcShowFeed ?? false) && $p->feed)<span>{{ $p->feed }}</span>@endif
    </span>
    <span class="pst-title">{{ $p->title }}</span>
    @if($p->body)<span class="pst-ex">{{ $p->excerpt(160) }}</span>@endif
  </span>
</a>
