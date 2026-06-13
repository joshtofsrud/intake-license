@extends('layouts.tenant.app')
{{-- MARKER-PATCH-258 — media library --}}
@section('title', 'Media')

@push('styles')
<style>
  .ml-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px; flex-wrap:wrap; }
  .ml-title { font-size:22px; font-weight:600; letter-spacing:-.02em; }
  .ml-sub { font-size:13px; color:var(--ia-dim,rgba(255,255,255,.55)); margin-top:3px; }
  .ml-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
  .ml-search { flex:1; min-width:200px; }
  .ml-search input { width:100%; }
  .ml-folders { display:flex; gap:6px; flex-wrap:wrap; }
  .ml-chip { font-size:12px; padding:6px 13px; border:.5px solid var(--ia-border,rgba(255,255,255,.13)); border-radius:999px; color:var(--ia-dim,rgba(255,255,255,.55)); background:none; cursor:pointer; text-decoration:none; }
  .ml-chip.on { background:var(--ia-accent,#BEF264); color:#0a0a0a; border-color:transparent; font-weight:600; }
  .ml-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
  .ml-card { border:.5px solid var(--ia-border,rgba(255,255,255,.13)); border-radius:11px; overflow:hidden; background:var(--ia-surface,#1c1c1c); position:relative; }
  .ml-thumb { aspect-ratio:1; background-size:cover; background-position:center; background-color:#111; }
  .ml-meta { padding:8px 10px; }
  .ml-name { font-size:11.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ml-dims { font-size:10px; color:var(--ia-dim,rgba(255,255,255,.5)); font-family:ui-monospace,monospace; margin-top:2px; }
  .ml-archive { position:absolute; top:7px; right:7px; width:24px; height:24px; border-radius:6px; border:none; background:rgba(0,0,0,.55); color:#fff; cursor:pointer; opacity:0; transition:opacity .12s; font-size:13px; line-height:1; }
  .ml-card:hover .ml-archive { opacity:1; }
  .ml-archive:hover { background:#d04444; }
  .ml-empty { border:.5px dashed var(--ia-border,rgba(255,255,255,.13)); border-radius:12px; padding:48px; text-align:center; color:var(--ia-dim,rgba(255,255,255,.5)); font-size:13.5px; }
  .ml-upload-btn { position:relative; overflow:hidden; }
  .ml-upload-btn input { position:absolute; inset:0; opacity:0; cursor:pointer; }
</style>
@endpush

@section('content')
<div class="ia-page">
  <div class="ml-head">
    <div>
      <div class="ml-title">Media</div>
      <div class="ml-sub">Images and files used across your site and pages. Upload once, reuse anywhere.</div>
    </div>
    <label class="ia-btn ia-btn-primary ml-upload-btn">
      + Upload
      <input type="file" id="ml-upload" accept="image/*" multiple>
    </label>
  </div>

  <div class="ml-controls">
    <form method="GET" class="ml-search">
      <input type="text" name="q" value="{{ $q }}" placeholder="Search by name…" class="ia-input"
             onkeydown="if(event.key==='Enter')this.form.submit()">
      @if($folder)<input type="hidden" name="folder" value="{{ $folder }}">@endif
    </form>
    <div class="ml-folders">
      <a href="{{ route('tenant.media.index', array_filter(['q'=>$q])) }}" class="ml-chip {{ !$folder ? 'on' : '' }}">All</a>
      @foreach($folders as $f)
        <a href="{{ route('tenant.media.index', array_filter(['folder'=>$f,'q'=>$q])) }}" class="ml-chip {{ $folder === $f ? 'on' : '' }}">{{ ucfirst(str_replace('_',' ',$f)) }}</a>
      @endforeach
    </div>
  </div>

  @if($media->isEmpty())
    <div class="ml-empty">
      No media yet. Click <strong>Upload</strong> to add images — they'll be available here and in the page builder.
    </div>
  @else
    <div class="ml-grid" id="ml-grid">
      @foreach($media as $m)
        <div class="ml-card" data-id="{{ $m->id }}">
          <div class="ml-thumb" style="background-image:url('{{ $m->url }}')"></div>
          <button class="ml-archive" title="Remove from library" onclick="mlArchive('{{ $m->id }}', this)">&times;</button>
          <div class="ml-meta">
            <div class="ml-name" title="{{ $m->original_name }}">{{ $m->original_name }}</div>
            <div class="ml-dims">{{ $m->width ? $m->width.'×'.$m->height : strtoupper(pathinfo($m->filename, PATHINFO_EXTENSION)) }} · {{ $m->bytes ? round($m->bytes/1024).'KB' : '' }}</div>
          </div>
        </div>
      @endforeach
    </div>
    <div style="margin-top:20px">{{ $media->links() }}</div>
  @endif
</div>

<script>
(function () {
  const csrf = '{{ csrf_token() }}';

  // Upload — reuses the shared uploads.store endpoint (257 records each row).
  const up = document.getElementById('ml-upload');
  if (up) up.addEventListener('change', async function () {
    const files = Array.from(this.files || []);
    if (!files.length) return;
    if (window.IntakeToast) IntakeToast.info('Uploading ' + files.length + ' file' + (files.length>1?'s':'') + '…');
    let ok = 0;
    for (const file of files) {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('type', '{{ $folder ?: 'general' }}');
      try {
        const r = await fetch('{{ route('tenant.uploads.store') }}', {
          method: 'POST', headers: { 'X-CSRF-TOKEN': csrf }, body: fd,
        });
        const d = await r.json();
        if (d.ok) ok++;
      } catch (e) { /* counted below */ }
    }
    if (window.IntakeToast) {
      ok === files.length ? IntakeToast.success('Uploaded ' + ok + ' file' + (ok>1?'s':''))
                          : IntakeToast.error('Uploaded ' + ok + ' of ' + files.length);
    }
    setTimeout(() => window.location.reload(), 700);
  });

  // Archive — soft-delete; file stays on disk so live pages keep rendering.
  window.mlArchive = async function (id, btn) {
    if (!confirm('Remove this from your library? Pages already using it keep working.')) return;
    try {
      const r = await fetch('{{ url('admin/media') }}/' + id + '/archive', {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf },
      });
      const d = await r.json();
      if (d.ok) {
        const card = btn.closest('.ml-card');
        if (card) card.remove();
        if (window.IntakeToast) IntakeToast.success('Removed from library');
      } else if (window.IntakeToast) IntakeToast.error('Could not remove');
    } catch (e) { if (window.IntakeToast) IntakeToast.error('Could not remove — check your connection'); }
  };
})();
</script>
@endsection
