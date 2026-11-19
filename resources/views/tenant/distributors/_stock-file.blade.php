{{-- MARKER-BRAND-STOCK: a brand-direct supplier's stock file. Included from connection.blade.php, outside the key form. --}}
@php
  $sf = $b['stock']['file'] ?? null;
  $ck = $sf?->check ?? null;
  $ftp = $b['stock']['ftp'] ?? [];
@endphp
<div style="border-top:.5px solid var(--ia-border);margin-top:14px;padding-top:14px">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:8px">Your {{ $b['label'] }} stock file</div>
  <div style="font-size:12.5px;line-height:1.55;color:var(--ia-text-dim);margin-bottom:12px">
    {{ $b['label'] }} sells to you direct, so your cost and stock come from the file {{ $b['label'] }} gives you
    (the Item Valuation export: Inventory ID, Base Price, Qty. Hard Available). Upload it here, or point us at the FTP folder it is dropped in.
    The file sets <b>your cost</b> and <b>quantity at {{ $b['label'] }}</b> for items you carry from {{ $b['label'] }}. It never changes your own on-hand counts, prices you set, or items from other suppliers.
  </div>

  @if ($sf && $sf->loaded_at)
    <div class="dc-stats" style="margin-bottom:12px">
      <div class="dc-stat"><div class="k">File</div><div class="v" style="font-size:13px;word-break:break-all">{{ $sf->file_name }}</div></div>
      <div class="dc-stat"><div class="k">Rows</div><div class="v">{{ number_format($sf->row_count) }}</div></div>
      <div class="dc-stat"><div class="k">In the catalog</div><div class="v">{{ number_format($ck['matched'] ?? 0) }}</div></div>
      <div class="dc-stat"><div class="k">Loaded</div><div class="v" style="font-size:13px">{{ \Carbon\Carbon::parse($sf->loaded_at)->diffForHumans() }} ({{ $sf->source === 'ftp' ? 'FTP' : 'upload' }})</div></div>
    </div>
    @if (! empty($ck['missing_count']))
      <div class="dc-note">{{ number_format($ck['missing_count']) }} row{{ $ck['missing_count'] === 1 ? '' : 's' }} in the file {{ $ck['missing_count'] === 1 ? 'is' : 'are' }} not in the {{ $b['label'] }} catalog yet, so {{ $ck['missing_count'] === 1 ? 'it has' : 'they have' }} no item to attach to. Parts and accessories are the usual reason.</div>
    @endif
  @else
    <div class="dc-note">No file loaded yet. Until one is, your {{ $b['label'] }} items have no cost or stock.</div>
  @endif
  @if ($sf && $sf->last_error)
    <div class="dc-banner dc-err">The last attempt failed: {{ $sf->last_error }}@if ($sf->last_attempt_at) ({{ \Carbon\Carbon::parse($sf->last_attempt_at)->diffForHumans() }})@endif. The file already loaded is still in use.</div>
  @endif

  <form method="POST" action="{{ route('tenant.distributors.connection.stock') }}" enctype="multipart/form-data" class="dc-row" style="align-items:flex-end">
    @csrf
    <input type="hidden" name="code" value="{{ $b['code'] }}">
    <div class="dc-field" style="max-width:380px;margin-bottom:0">
      <label>Upload a file (.xlsx or .csv)</label>
      <input class="dc-input" type="file" name="file" accept=".xlsx,.csv,.txt" required>
    </div>
    <div><button type="submit" class="dc-btn primary">Load file</button></div>
  </form>
  @error('file')<div class="dc-banner dc-err" style="margin-top:10px">{{ $message }}</div>@enderror

  <form method="POST" action="{{ route('tenant.distributors.connection.stock.ftp') }}" style="margin-top:16px">
    @csrf
    <input type="hidden" name="code" value="{{ $b['code'] }}">
    <div class="dc-row">
      <div class="dc-field"><label>FTP host</label><input class="dc-input" type="text" name="host" value="{{ $ftp['host'] ?? '' }}" autocomplete="off" placeholder="ftp.example.com"></div>
      <div class="dc-field"><label>FTP user</label><input class="dc-input" type="text" name="user" value="{{ $ftp['user'] ?? '' }}" autocomplete="off"></div>
      <div class="dc-field"><label>FTP password</label><input class="dc-input" type="password" name="password" autocomplete="new-password" placeholder="{{ ! empty($b['stock']['ftpPass']) ? 'saved; leave blank to keep it' : '' }}"></div>
      <div class="dc-field"><label>Folder</label><input class="dc-input" type="text" name="folder" value="{{ $ftp['folder'] ?? '' }}" autocomplete="off" placeholder="blank for the top folder"></div>
    </div>
    <button type="submit" class="dc-btn">Save FTP folder</button>
  </form>
  @if (! empty($ftp['host']) && ! empty($ftp['user']))
    <form method="POST" action="{{ route('tenant.distributors.connection.stock.fetch') }}" style="margin-top:10px">
      @csrf
      <input type="hidden" name="code" value="{{ $b['code'] }}">
      <button type="submit" class="dc-btn">Fetch now</button>
      <span class="dc-dim" style="font-size:11.5px;margin-left:8px">Takes the newest .xlsx, .csv or .txt in the folder. Also checked every night.</span>
    </form>
  @endif
</div>
