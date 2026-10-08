@extends('layouts.tenant.app')
{{-- media library --}}
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
  /* tile actions sit together; on touch screens (no hover) they're always shown. */
  .ml-acts { position:absolute; top:7px; right:7px; display:flex; gap:5px; opacity:0; transition:opacity .12s; }
  .ml-card:hover .ml-acts, .ml-card:focus-within .ml-acts { opacity:1; }
  @media (hover: none) { .ml-acts { opacity:1; } }
  .ml-archive, .ml-del { width:24px; height:24px; border-radius:6px; border:none; background:rgba(0,0,0,.55); color:#fff; cursor:pointer; font-size:13px; line-height:1; display:flex; align-items:center; justify-content:center; padding:0; }
  .ml-archive:hover { background:rgba(0,0,0,.8); }
  .ml-del:hover { background:#d04444; }
  .ml-chip--arch { margin-left:6px; }
  .ml-empty { border:.5px dashed var(--ia-border,rgba(255,255,255,.13)); border-radius:12px; padding:48px; text-align:center; color:var(--ia-dim,rgba(255,255,255,.5)); font-size:13.5px; }
  .ml-upload-btn { position:relative; overflow:hidden; }
  .ml-upload-btn input { position:absolute; inset:0; opacity:0; cursor:pointer; }
  .ml-meter { border:.5px solid var(--ia-border,rgba(255,255,255,.13)); border-radius:11px; padding:12px 14px; margin-bottom:18px; background:var(--ia-surface,#1c1c1c); }
  .ml-meter-row { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; font-size:13px; }
  .ml-meter-row b { font-weight:600; font-variant-numeric:tabular-nums; }
  .ml-meter-plan { font-size:11.5px; color:var(--ia-dim,rgba(255,255,255,.55)); }
  .ml-meter-state { margin-left:auto; font-size:11.5px; font-weight:600; }
  .ml-meter-state.near { color:#F0C46A; }
  .ml-meter-state.full { color:#F08A8A; }
  .ml-meter-bar { height:6px; border-radius:3px; background:rgba(127,127,127,.18); overflow:hidden; margin-top:8px; }
  .ml-meter-bar i { display:block; height:100%; background:var(--ia-accent,#BEF264); }
  .ml-meter-bar i.near { background:#F0C46A; }
  .ml-meter-bar i.full { background:#F08A8A; }
  .ml-meter-legend { font-size:11.5px; color:var(--ia-dim,rgba(255,255,255,.55)); line-height:1.55; margin-top:8px; }
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

  <div class="ml-meter">
    <div class="ml-meter-row">
      @if($storage['limit'] > 0)
        <span><b>{{ $storage['used_h'] }}</b> of {{ $storage['limit_h'] }} used</span>
        <span class="ml-meter-plan">{{ ucfirst($storage['tier']) }} plan</span>
        @if($storage['state'] === 'full')
          <span class="ml-meter-state full">Full: new uploads are refused</span>
        @elseif($storage['state'] === 'near')
          <span class="ml-meter-state near">{{ $storage['pct'] }}% used</span>
        @endif
      @else
        <span><b>{{ $storage['used_h'] }}</b> used</span>
        <span class="ml-meter-plan">No storage limit on this account</span>
      @endif
    </div>
    @if($storage['limit'] > 0)
      <div class="ml-meter-bar"><i class="{{ $storage['state'] }}" style="width: {{ max($storage['pct'], $storage['used'] > 0 ? 1 : 0) }}%"></i></div>
    @endif
    <div class="ml-meter-legend">
      Counts every image you've uploaded: this library ({{ $storage['library_h'] }}, archived images included)
      and email campaign images ({{ $storage['campaign_h'] }}). Uploads from the page builder, inventory and campaigns
      all draw from this one allowance. <b>Archive</b> (&times;) hides an image but keeps its file, so it still counts.
      <b>Delete</b> removes the file and frees the space, and is refused while the image is used anywhere.
    </div>
  </div>

  <div class="ml-controls">
    <form method="GET" class="ml-search">
      <input type="text" name="q" value="{{ $q }}" placeholder="Search by name…" class="ia-input"
             onkeydown="if(event.key==='Enter')this.form.submit()">
      @if($folder)<input type="hidden" name="folder" value="{{ $folder }}">@endif
    </form>
    <div class="ml-folders">
      <a href="{{ route('tenant.media.index', array_filter(['q'=>$q])) }}" class="ml-chip {{ !$folder && !$archived ? 'on' : '' }}">All</a>
      @foreach($folders as $f)
        <a href="{{ route('tenant.media.index', array_filter(['folder'=>$f,'q'=>$q])) }}" class="ml-chip {{ $folder === $f ? 'on' : '' }}">{{ ucfirst(str_replace('_',' ',$f)) }}</a>
      @endforeach
      <a href="{{ route('tenant.media.index', array_filter(['archived'=>1,'q'=>$q])) }}" class="ml-chip ml-chip--arch {{ $archived ? 'on' : '' }}">Archived</a>
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
          {{-- Archive hides; Delete removes the file. --}}
          <div class="ml-acts">
            @if(! $archived)
              <button type="button" class="ml-archive" title="Archive: hide from the library, keep the file" onclick="mlArchive('{{ $m->id }}', this)">&times;</button>
            @endif
            @if($canDelete)
              <button type="button" class="ml-del" title="Delete: remove the file and free its space" data-ml-delete="{{ $m->id }}">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>
            @endif
          </div>
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
    let ok = 0, firstErr = '';
    for (const file of files) {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('type', '{{ $folder ?: 'general' }}');
      try {
        const r = await fetch('{{ route('tenant.uploads.store') }}', {
          method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: fd,
        });
        const d = await r.json();
        if (d.ok) ok++;
        else if (!firstErr) firstErr = d.message || (d.errors && Object.values(d.errors)[0] && Object.values(d.errors)[0][0]) || '';
      } catch (e) { /* counted below */ }
    }
    if (window.IntakeToast) {
      ok === files.length ? IntakeToast.success('Uploaded ' + ok + ' file' + (ok>1?'s':''))
                          : IntakeToast.error('Uploaded ' + ok + ' of ' + files.length + (firstErr ? '. ' + firstErr : ''));
    }
    setTimeout(() => window.location.reload(), 700);
  });

  // Delete: in-app dialog, server refuses if the image is in use.
  document.addEventListener('click', async function (e) {
    const btn = e.target.closest ? e.target.closest('[data-ml-delete]') : null;
    if (!btn || !window.IntakeConfirm) return;
    const ok = await IntakeConfirm.show({
      title: 'Delete this image?',
      message: 'The file is removed and its space freed. This can\'t be undone. If the image is used anywhere, it won\'t be deleted.',
      confirmText: 'Delete', danger: true,
    });
    if (!ok) return;
    try {
      const r = await fetch('{{ url('admin/media') }}/' + btn.getAttribute('data-ml-delete'), {
        method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      });
      const d = await r.json().catch(() => ({}));
      if (d.ok) {
        const card = btn.closest('.ml-card');
        if (card) card.remove();
        if (window.IntakeToast) IntakeToast.success('Deleted' + (d.freed ? ', ' + d.freed + ' freed' : ''));
        return;
      }
      if (d.used_in && d.used_in.length) {
        IntakeConfirm.alert({ title: 'Still in use', message: 'Used in: ' + d.used_in.join(', ') + '. Remove it from those places first, then delete it.' });
      } else {
        IntakeConfirm.alert({ title: 'Not deleted', message: d.message || 'Please try again.' });
      }
    } catch (err) {
      IntakeConfirm.alert({ title: 'Not deleted', message: 'Check your connection and try again.' });
    }
  });

  // Archive — soft-delete; file stays on disk so live pages keep rendering.
  window.mlArchive = async function (id, btn) {
    if (!(await iaConfirm('Remove this from your library? Pages already using it keep working.'))) return;
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
