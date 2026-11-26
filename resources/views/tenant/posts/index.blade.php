@extends('layouts.tenant.app')
@php $pageTitle = 'Posts'; @endphp
{{-- MARKER-POSTS --}}
@push('styles')
<style>
  .po-new{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
  .po-new input{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:9px 12px;font-size:13px;font-family:inherit}
  .po-new input:focus{outline:none;border-color:var(--ia-accent)}
  .po-new .t{flex:1;min-width:240px}.po-new .f{width:200px}
  .po-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
  .po-chip{font-size:12px;padding:5px 12px;border:.5px solid var(--ia-border);border-radius:999px;color:var(--ia-text-dim);text-decoration:none}
  .po-chip.on{background:var(--ia-accent);color:#0a0a0a;border-color:transparent;font-weight:600}
  .po-row{display:grid;grid-template-columns:56px minmax(0,1fr) 150px 110px 90px;gap:12px;align-items:center;padding:10px 4px;border-bottom:.5px solid var(--ia-border);font-size:13px;color:var(--ia-text);text-decoration:none}
  .po-row:hover{background:var(--ia-surface)}
  .po-thumb{width:56px;height:40px;border-radius:6px;background:var(--ia-surface-2) center/cover no-repeat}
  .po-row b{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
  .po-dim{color:var(--ia-text-dim);font-size:12px}
  .po-pill{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:100px;padding:2px 8px;border:.5px solid var(--ia-border);color:var(--ia-text-dim);justify-self:start}
  .po-pill.live{border-color:var(--ia-accent);color:var(--ia-accent)}
  .po-pill.soon{border-color:#F0C46A;color:#F0C46A}
  .po-empty{border:.5px dashed var(--ia-border);border-radius:12px;padding:40px;text-align:center;color:var(--ia-text-dim);font-size:13.5px}
  @media (max-width:700px){.po-row{grid-template-columns:48px minmax(0,1fr) 80px}.po-row .po-hide{display:none}}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Posts</h1>
    <p class="ia-page-subtitle">Dated updates for your site — add them to a page with the Posts section.</p>
  </div>
  @if(count($feeds))<a class="ia-btn" href="/posts" target="_blank" rel="noopener">View on site ↗</a>@endif
</div>

@if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ route('tenant.posts.store') }}" class="po-new">
  @csrf
  <input class="t" name="title" placeholder="New post title" required maxlength="200">
  <input class="f" name="feed" list="poFeeds" placeholder="Feed (e.g. Trail conditions)" value="{{ $feed }}" maxlength="60" title="Posts are grouped by feed; each Posts section shows one feed">
  <datalist id="poFeeds">@foreach($feeds as $fd)<option value="{{ $fd }}">@endforeach</datalist>
  <button class="ia-btn ia-btn--primary" type="submit">Start post</button>
</form>

@if(count($feeds) > 1)
  <div class="po-chips">
    <a class="po-chip {{ $feed === '' ? 'on' : '' }}" href="{{ route('tenant.posts.index') }}">All</a>
    @foreach($feeds as $fd)<a class="po-chip {{ $feed === $fd ? 'on' : '' }}" href="{{ route('tenant.posts.index', ['feed' => $fd]) }}">{{ $fd }}</a>@endforeach
  </div>
@endif

@forelse($posts as $p)
  <a class="po-row" href="{{ route('tenant.posts.edit', $p->id) }}">
    <span class="po-thumb" @if($p->image_url) style="background-image:url('{{ e($p->image_url) }}')" @endif></span>
    <span><b>{{ $p->title }}</b><span class="po-dim">{{ $p->excerpt(90) ?: 'No text yet' }}</span></span>
    <span class="po-dim po-hide">{{ $p->feed }}</span>
    <span class="po-dim po-hide">{{ $p->published_at ? tlocal($p->published_at, 'M j, Y') : '—' }}</span>
    @if(! $p->published_at)<span class="po-pill">Draft</span>
    @elseif($p->published_at->isFuture())<span class="po-pill soon" title="Goes live {{ tlocal($p->published_at, 'M j, g:i A') }}">Scheduled</span>
    @else<span class="po-pill live">Live</span>@endif
  </a>
@empty
  <div class="po-empty">No posts yet. Give the first one a title above.</div>
@endforelse

@if($posts->hasPages())<div style="margin-top:16px">{{ $posts->links() }}</div>@endif
@endsection
