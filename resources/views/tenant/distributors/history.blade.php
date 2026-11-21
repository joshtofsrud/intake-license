{{-- what bulk changes have been made, and the way back. --}}
@extends('layouts.tenant.app')
@section('title', 'Catalog changes')

@section('content')
<div class="ia-page">
  <div class="ia-page-head">
    <h1 class="ia-page-title">Catalog changes</h1>
    @if(tenant()->distributor_sync_enabled)
      <a href="{{ route('tenant.distributors.attention') }}" class="ia-back-link">← Catalog attention</a>
    @else
      <a href="{{ route('tenant.inventory.cpos') }}" class="ia-back-link">← Import</a>
    @endif
  </div>

  <p class="ch-hint">
    Every bulk action is one batch. Undo puts the items back as they were — except anything edited since,
    which is kept and counted, because throwing away newer work would be worse than not undoing at all.
    <br><b>Batches are kept for 90 days.</b> After that a batch drops off this list and can no longer be undone.
  </p>

  @forelse($batches as $b)
    <div class="ch-batch">
      <div class="ch-top">
        <span class="ch-what">{{ $b->label() }}</span>
        @if($b->isUndone())
          <span class="ch-pill ch-undone">undone</span>
        @elseif($b->kept_count > 0)
          <span class="ch-pill ch-part">partly undone</span>
        @else
          <span class="ch-pill ch-done">applied</span>
        @endif
        <span class="ch-when">{{ $b->created_at->diffForHumans() }}</span>
      </div>

      <div class="ch-meta">
        {{ number_format($b->item_count) }} {{ \Illuminate\Support\Str::plural('item', $b->item_count) }}
        @if($b->isPosImport())
          · {{ ! empty($b->filter['picked']) ? 'picked one at a time' : (! empty($b->filter['ids']) ? 'ticked rows' : 'everything matching the filters') }}
        @endif
        @if($b->filter['select_all'] ?? false)
          · every match for the filter
        @endif
        @if($b->filter['reason'] ?? null) · {{ str_replace('_', ' ', $b->filter['reason']) }} @endif
        @if($b->run_by) · {{ $b->run_by }} @endif
      </div>

      @if($b->isUndone())
        <div class="ch-meta">
          {{ number_format($b->restored_count) }} put back
          @if($b->kept_count > 0)
            · <span class="ch-kept">{{ number_format($b->kept_count) }} kept because they {{ $b->isPosImport() ? 'have been sold or adjusted since' : 'were edited since' }}</span>
          @endif
        </div>
      @endif

      <div class="ch-acts">
        <a href="{{ route('tenant.distributors.attention.history.show', $b->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">
          See the items
        </a>
        @if($b->isReversible())
          {{-- IntakeConfirm, not a browser dialog. The
               existing data-confirm helper binds to the element's own click and
               calls native confirm(), which is against the house rule. --}}
          <form method="POST" action="{{ route('tenant.distributors.attention.history.undo', $b->id) }}"
                id="undo-{{ $b->id }}" style="display:inline">
            @csrf
            <button type="button" class="ia-btn ia-btn--danger ia-btn--sm"
                    onclick="undoBatch('{{ $b->id }}', {{ (int) $b->item_count }}, {{ $b->isPosImport() ? 'true' : 'false' }})">
              Undo this batch
            </button>
          </form>
        @endif
      </div>
    </div>
  @empty
    <div class="ch-empty">
      No bulk catalog changes recorded yet. Anything you adopt or dismiss from Catalog attention will appear
      here, with a way back.
    </div>
  @endforelse
</div>

@push('styles')
<style>
  .ch-hint{font-size:13px;color:var(--ia-text-dim);line-height:1.6;max-width:74ch;margin-bottom:16px}
  .ch-batch{border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:13px 15px;margin-bottom:10px}
  .ch-top{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  .ch-what{font-size:14.5px;font-weight:600}
  .ch-when{font-size:12px;color:var(--ia-text-dim);margin-left:auto}
  .ch-meta{font-size:12.5px;color:var(--ia-text-dim);margin-top:4px}
  .ch-kept{color:var(--ia-warn,#F0C46A)}
  .ch-pill{font-size:11px;font-weight:600;border-radius:99px;padding:2px 9px}
  .ch-done{background:rgba(143,217,143,.14);color:#8FD98F}
  .ch-undone{background:rgba(255,255,255,.07);color:var(--ia-text-dim)}
  .ch-part{background:rgba(240,196,106,.14);color:#F0C46A}
  .ch-acts{display:flex;gap:8px;margin-top:11px;flex-wrap:wrap;align-items:center}
  .ch-empty{border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:18px;
    font-size:13px;color:var(--ia-text-dim);line-height:1.6}
  @media(max-width:720px){
    .ch-when{margin-left:0;width:100%}
    .ch-acts .ia-btn{flex:1}
  }
</style>
@endpush

@push('scripts')
<script>
  // the app's own dialog. Undoing thousands of items is
  // exactly the moment a confirmation should look like it belongs here.
  function undoBatch(id, count, posImport) {
    var n = count.toLocaleString();
    IntakeConfirm.show({
      title: (posImport ? 'Undo this import of ' : 'Put back ') + n + ' item' + (count === 1 ? '' : 's') + '?',
      message: posImport
        ? 'Links to items you already had are removed. Items this import made are removed too, with the stock your Connected POS gave them. Anything sold or adjusted since is kept, and you will be told how many. Nothing changes in your Connected POS.'
        : 'They go back to the values they had before this batch. Anything edited since is left as it is, and you will be told how many.',
      confirmText: posImport ? 'Undo the import' : 'Put them back',
      danger: true
    }).then(function (ok) {
      if (ok) { document.getElementById('undo-' + id).submit(); }
    });
  }
</script>
@endpush
@endsection
