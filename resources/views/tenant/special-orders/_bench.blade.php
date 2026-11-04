{{-- MARKER-SO-HANDOVER: the Arrived bench. Each row says whether the part
     is held, who it's for and how it gets to them, whether they've been told,
     and offers the next step. Expects $sos and $bench. --}}
<style>
  .so-flat{background:transparent;border:0;box-shadow:none}
  .so-flat .ia-table{border-top:1px solid var(--ia-border)}
  .so-hold{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;border-radius:99px;padding:2px 9px;white-space:nowrap}
  .so-hold.on{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  body.ia-theme-b .so-hold.on{background:rgba(59,109,17,.10);color:#3B6D11}
  .so-hold.off{background:rgba(245,196,81,.12);color:#c9972a}
  .so-hold.na{background:var(--ia-surface);color:var(--ia-text-muted)}
  .so-sub{display:block;font-size:11.5px;color:var(--ia-text-muted);font-weight:400}
  .so-acts{display:flex;gap:6px;justify-content:flex-end;align-items:center;white-space:nowrap}
  .so-nw{white-space:nowrap}
  .so-bench-btns{display:flex;gap:6px;margin-top:4px;flex-wrap:wrap}
</style>

@php
  $holdHtml = function ($b) {
      $h = $b['hold'];
      return match ($h['state']) {
          'held'        => '<span class="so-hold on">Held' . ($h['at'] ? ' at ' . e($h['at']) : '') . '</span>',
          'taken'       => '<span class="so-hold na">Counted out at sale</span><span class="so-sub">Its sale took the stock already</span>',
          'uncataloged' => '<span class="so-hold na">Not in catalog</span><span class="so-sub">Nothing in stock to hold</span>',
          'stock'       => '<span class="so-hold na">In stock</span><span class="so-sub">Nobody is waiting for it</span>',
          default       => '<span class="so-hold off">Not held</span><span class="so-sub">Check the shelf, then hold it</span>',
      };
  };
@endphp

{{-- Desktop --}}
<div class="ia-card so-flat so-desktop-only">
  <table class="ia-table">
    <thead><tr><th>SO</th><th>Item</th><th>For</th><th>Hold</th><th>Arrived</th><th>Customer told</th><th></th></tr></thead>
    <tbody>
      @foreach($sos as $so)
        @php
          $b = $bench[$so->id];
          $ctx = $b['ctx'];
          $who = $so->customer ? $so->customer->fullName() : ($so->appointment ? 'Work order ' . $so->appointment->ra_number : 'Shop stock');
        @endphp
        <tr data-so-row="{{ $so->id }}">
          <td class="so-nw"><a href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}" style="color:inherit;text-decoration:none"><strong>{{ $so->so_number }}</strong></a></td>
          <td><strong>{{ $so->item_name_snapshot }}</strong>@if($so->quantity > 1) <span class="ia-text-muted">×{{ $so->quantity }}</span>@endif
            @if($so->item && $so->item->sku)<span class="so-sub">{{ $so->item->sku }}</span>@endif</td>
          <td><strong>{{ $who }}</strong>@if($ctx['label'] !== $who)<span class="so-sub">{{ $ctx['label'] }}</span>@endif</td>
          <td>{!! $holdHtml($b) !!}
            @if($b['hold']['state'] === 'none')
              <div class="so-bench-btns">
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="hold" data-id="{{ $so->id }}">Hold now</button>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="missing" data-id="{{ $so->id }}" data-so="{{ $so->so_number }}">Not on shelf</button>
              </div>
            @endif
          </td>
          <td class="so-nw">{{ $so->arrived_at ? tlocal_date($so->arrived_at, 'M j') : '—' }}</td>
          <td>
            @if($so->notified_at)
              {{ $so->notified_via }} <span class="so-sub">{{ tlocal_date($so->notified_at, 'M j') }}</span>
            @elseif($so->customer_id || $so->appointment_id)
              <span class="ia-text-muted">Not yet</span>
            @else
              <span class="ia-text-muted">—</span>
            @endif
            @if($so->customer_id || $so->appointment_id)
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="tell" data-id="{{ $so->id }}">{{ $so->notified_at ? 'Again' : 'Tell' }}</button>
            @endif
          </td>
          <td>
            <div class="so-acts">
              @include('tenant.special-orders._handover-button', ['so' => $so, 'ctx' => $ctx, 'who' => $who, 'hold' => $b['hold'], 'size' => 'sm'])
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="cancel" data-id="{{ $so->id }}">Cancel</button>
            </div>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>

{{-- Mobile --}}
<div class="so-mobile-only so-cards">
  @foreach($sos as $so)
    @php
      $b = $bench[$so->id];
      $ctx = $b['ctx'];
      $who = $so->customer ? $so->customer->fullName() : ($so->appointment ? 'Work order ' . $so->appointment->ra_number : 'Shop stock');
    @endphp
    <div class="so-card">
      <div class="so-card-top">
        <a class="so-card-num" href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}" style="text-decoration:none">{{ $so->so_number }}</a>
        {!! $holdHtml(['hold' => ['state' => $b['hold']['state'], 'at' => $b['hold']['at']]]) !!}
      </div>
      <div class="so-card-item">{{ $so->item_name_snapshot }} <span class="ia-text-muted">×{{ $so->quantity }}</span></div>
      <div class="so-card-meta">{{ $who }}@if($ctx['label'] !== $who) · {{ $ctx['label'] }}@endif
        @if($so->customer_id || $so->appointment_id) · {{ $so->notified_at ? 'Told: ' . $so->notified_via : 'Not told yet' }}@endif</div>
      <div class="so-bench-btns" style="margin-top:10px">
        @include('tenant.special-orders._handover-button', ['so' => $so, 'ctx' => $ctx, 'who' => $who, 'hold' => $b['hold'], 'size' => 'sm'])
        @if($so->customer_id || $so->appointment_id)
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="tell" data-id="{{ $so->id }}">{{ $so->notified_at ? 'Tell again' : 'Tell' }}</button>
        @endif
        @if($b['hold']['state'] === 'none')
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="hold" data-id="{{ $so->id }}">Hold now</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="missing" data-id="{{ $so->id }}" data-so="{{ $so->so_number }}">Not on shelf</button>
        @endif
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="cancel" data-id="{{ $so->id }}">Cancel</button>
      </div>
    </div>
  @endforeach
</div>

@include('tenant.special-orders._handover-js')
