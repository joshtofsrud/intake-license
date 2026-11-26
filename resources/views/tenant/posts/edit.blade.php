@extends('layouts.tenant.app')
@php $pageTitle = 'Edit post'; $isLive = $post->isLive(); @endphp
{{-- MARKER-POSTS --}}
@push('styles')
<style>
  .pe-wrap{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:24px;align-items:start}
  .pe-f{margin-bottom:14px}
  .pe-f label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-dim);margin-bottom:5px}
  .pe-f input,.pe-f textarea,.pe-f select{width:100%;box-sizing:border-box;background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:9px 12px;font-size:14px;font-family:inherit}
  .pe-f textarea{min-height:340px;line-height:1.6;resize:vertical}
  .pe-f input:focus,.pe-f textarea:focus,.pe-f select:focus{outline:none;border-color:var(--ia-accent)}
  .pe-title input{font-size:20px;font-weight:600;padding:11px 14px}
  .pe-side{position:sticky;top:16px}
  .pe-img{aspect-ratio:16/9;border-radius:var(--ia-r-md);background:var(--ia-surface-2) center/cover no-repeat;border:.5px dashed var(--ia-border);display:grid;place-items:center;color:var(--ia-text-dim);font-size:12px;margin-bottom:8px}
  .pe-img.has{border-style:solid;color:transparent}
  .pe-up{position:relative;overflow:hidden}.pe-up input{position:absolute;inset:0;opacity:0;cursor:pointer}
  .pe-seg{display:flex;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);overflow:hidden}
  .pe-seg label{flex:1;text-align:center;padding:8px;font-size:13px;cursor:pointer;margin:0;text-transform:none;letter-spacing:0;color:var(--ia-text-dim)}
  .pe-seg input{display:none}
  .pe-seg input:checked + span{color:#0a0a0a}
  .pe-seg label:has(input:checked){background:var(--ia-accent);color:#0a0a0a;font-weight:600}
  .pe-acts{display:flex;gap:8px;align-items:center;margin-top:6px}
  .pe-hint{font-size:11.5px;color:var(--ia-text-dim);margin-top:5px;line-height:1.5}
  @media (max-width:860px){.pe-wrap{grid-template-columns:1fr}.pe-side{position:static}}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <a href="{{ route('tenant.posts.index') }}" class="po-back" style="font-size:12.5px;color:var(--ia-text-dim);text-decoration:none">← Posts</a>
    <h1 class="ia-page-title" style="margin-top:4px">{{ $post->title }}</h1>
  </div>
  @if($isLive)<a class="ia-btn" href="{{ $post->url() }}" target="_blank" rel="noopener">View live ↗</a>@endif
</div>

@if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ route('tenant.posts.update', $post->id) }}" id="peForm">
  @csrf @method('PATCH')
  <div class="pe-wrap">
    <div>
      <div class="pe-f pe-title"><label>Title</label><input name="title" value="{{ old('title', $post->title) }}" required maxlength="200"></div>
      <div class="pe-f"><label>Post</label><textarea name="body" placeholder="Write it here. A blank line starts a new paragraph.">{{ old('body', $post->body) }}</textarea></div>
    </div>
    <aside class="pe-side">
      <div class="pe-f">
        <label>Status</label>
        <div class="pe-seg">
          <label><input type="radio" name="status" value="draft" @checked(! $post->published_at)><span>Draft</span></label>
          <label><input type="radio" name="status" value="published" @checked((bool) $post->published_at)><span>Published</span></label>
        </div>
      </div>
      <div class="pe-f">
        <label>Date</label>
        <input type="datetime-local" name="date" value="{{ $post->published_at ? tlocal($post->published_at, 'Y-m-d\TH:i') : '' }}">
        <div class="pe-hint">Blank publishes now. A future date schedules it.</div>
      </div>
      <div class="pe-f">
        <label>Feed</label>
        <input name="feed" list="peFeeds" value="{{ old('feed', $post->feed) }}" maxlength="60">
        <datalist id="peFeeds">@foreach($feeds as $fd)<option value="{{ $fd }}">@endforeach</datalist>
      </div>
      <div class="pe-f">
        <label>Photo</label>
        <div class="pe-img {{ $post->image_url ? 'has' : '' }}" id="peImg" @if($post->image_url) style="background-image:url('{{ e($post->image_url) }}')" @endif>No photo</div>
        <input type="hidden" name="image_url" id="peImgUrl" value="{{ $post->image_url }}">
        <div style="display:flex;gap:6px">
          <label class="ia-btn ia-btn--sm pe-up" style="margin:0;text-transform:none;letter-spacing:0;font-size:12.5px;color:var(--ia-text)">Upload<input type="file" accept="image/*" id="peFile"></label>
          <button type="button" class="ia-btn ia-btn--sm" id="peImgClear" @unless($post->image_url) hidden @endunless>Remove</button>
        </div>
        <div class="pe-hint" id="peUpMsg"></div>
      </div>
      <div class="pe-acts">
        <button type="submit" class="ia-btn ia-btn--primary" style="flex:1">Save</button>
      </div>
    </aside>
  </div>
</form>

<form method="POST" action="{{ route('tenant.posts.destroy', $post->id) }}" style="margin-top:28px"
      data-confirm-title="Delete this post?" data-confirm-msg="It comes off your site straight away. This can't be undone." data-confirm-yes="Delete" data-confirm-danger="1">
  @csrf @method('DELETE')
  <button type="submit" class="ia-btn ia-btn--sm" style="color:#F08A8A">Delete post</button>
</form>

<script>
(function () {
  var file = document.getElementById('peFile'), img = document.getElementById('peImg'),
      url = document.getElementById('peImgUrl'), clr = document.getElementById('peImgClear'), msg = document.getElementById('peUpMsg');
  var csrf = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '{{ csrf_token() }}';
  function show(u) {
    url.value = u || '';
    img.style.backgroundImage = u ? "url('" + u.replace(/'/g, '%27') + "')" : '';
    img.classList.toggle('has', !!u); clr.hidden = !u;
  }
  file.addEventListener('change', function () {
    if (!file.files[0]) return;
    var fd = new FormData(); fd.append('file', file.files[0]); fd.append('type', 'general');
    msg.textContent = 'Uploading…';
    fetch('{{ route('tenant.uploads.store') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.url) { msg.textContent = (j && j.message) || 'Upload failed.'; return; }
        show(j.url); msg.textContent = 'Uploaded — Save to keep it.';
      })
      .catch(function () { msg.textContent = 'Upload failed — check your connection.'; });
    file.value = '';
  });
  clr.addEventListener('click', function () { show(''); msg.textContent = 'Removed — Save to keep the change.'; });
})();
</script>
@endsection
