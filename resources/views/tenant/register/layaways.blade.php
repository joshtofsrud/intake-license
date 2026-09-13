@extends('layouts.tenant.app')
@php $pageTitle = 'Layaways'; @endphp

{{-- MARKER-LAYAWAY-NAV — the tab styles live per page in this app;
     copying the markup without them is why this rendered as plain text. --}}
@push('styles')
<style>
  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  /* MARKER-REG-MOBILE ------------------------------------------------- */
  /* display:contents keeps the links as direct flex children of the bar on
     desktop, so nothing about the existing layout changes. */
  .reg-tabs-scroll{display:contents}

  @media (max-width: 760px){
    .ia-page-subtitle{display:none}

    .reg-tabs-bar{display:block;flex-wrap:nowrap}
    .reg-tabs-scroll{
      display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;
      -webkit-overflow-scrolling:touch
    }
    .reg-tabs-scroll::-webkit-scrollbar{display:none}
    .reg-tab-link{white-space:nowrap;flex:0 0 auto;padding:10px 14px}
  }

  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .quotes-empty{
    padding:60px 20px;text-align:center;color:var(--ia-text-dim);
    border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-lg);
    background:var(--ia-surface)
  }
  .quotes-empty h3{font-size:16px;color:var(--ia-text);margin-bottom:6px;font-weight:500}
  .quotes-empty p{font-size:13px;line-height:1.5;max-width:420px;margin:0 auto}

  .quotes-table-wrap{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);overflow:hidden
  }
  .quotes-table{width:100%;border-collapse:collapse}
  .quotes-table thead th{
    text-align:left;padding:12px 16px;font-size:11px;font-weight:600;
    color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.06em;
    background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border)
  }
  .quotes-table tbody td{
    padding:14px 16px;font-size:13px;color:var(--ia-text);
    border-bottom:0.5px solid var(--ia-border);vertical-align:middle
  }
  .quotes-table tbody tr:last-child td{border-bottom:none}
  .quotes-table tbody tr:hover{background:var(--ia-hover)}

  .q-customer-name{font-weight:500}
  .q-customer-email{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .q-meta-line{color:var(--ia-text-dim);font-size:12px}
  .q-total{font-weight:600;text-align:right;font-variant-numeric:tabular-nums}
  .q-actions{display:flex;gap:6px;justify-content:flex-end}
  .q-btn-convert{
    padding:6px 12px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-sm);font-size:12px;font-weight:500;
    font-family:inherit;cursor:pointer
  }
  .q-btn-convert:hover{filter:brightness(.93)}
  .q-btn-discard{
    padding:6px 10px;background:transparent;border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-sm);color:var(--ia-text-dim);font-size:12px;
    font-family:inherit;cursor:pointer
  }
  .q-btn-discard:hover{color:#F09595;border-color:#F09595}

  .quotes-count{font-size:13px;color:var(--ia-text-dim)}

  .q-dashboard{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:12px;margin-bottom:24px
  }
  .q-card{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);padding:16px;cursor:pointer;
    transition:border-color var(--ia-t),background var(--ia-t);
    position:relative
  }
  .q-card:hover{border-color:var(--ia-border-strong);background:var(--ia-hover)}
  .q-card.active{border-color:var(--ia-accent);background:rgba(190,242,100,.04)}
  .q-card .q-card-label{
    font-size:11px;text-transform:uppercase;letter-spacing:.06em;
    color:var(--ia-text-dim);font-weight:600;margin-bottom:8px
  }
  .q-card .q-card-primary{
    font-size:24px;font-weight:600;color:var(--ia-text);
    font-variant-numeric:tabular-nums;line-height:1.1
  }
  .q-card .q-card-meta{
    font-size:12px;color:var(--ia-text-dim);margin-top:6px
  }
  .q-card.urgent .q-card-primary{color:#FFB450}
  .q-card.critical .q-card-primary{color:#F09595}
  .q-card.healthy .q-card-primary{color:var(--ia-accent)}

  .q-clear-filter{
    margin-left:8px;padding:4px 10px;background:transparent;
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text-dim);font-size:11px;font-family:inherit;
    cursor:pointer;display:none
  }
  .q-clear-filter:hover{color:var(--ia-text);border-color:var(--ia-border-strong)}
  .q-clear-filter.visible{display:inline-block}

  .quotes-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin-bottom:14px;flex-wrap:wrap
  }
  .quotes-search{
    flex:1;min-width:220px;max-width:360px;padding:9px 12px;
    background:var(--ia-input-bg);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;
    font-family:inherit
  }
  .quotes-search:focus{outline:none;border-color:var(--ia-accent)}

  .quotes-table thead th.sortable{cursor:pointer;user-select:none}
  .quotes-table thead th.sortable:hover{color:var(--ia-text)}
  .quotes-table thead th .sort-arrow{
    display:inline-block;margin-left:4px;font-size:10px;
    color:var(--ia-text-muted);opacity:.5
  }
  .quotes-table thead th.sort-active .sort-arrow{
    color:var(--ia-accent);opacity:1
  }

  .quotes-empty-search{
    padding:40px 20px;text-align:center;color:var(--ia-text-dim);
    font-size:13px
  }

  .reg-modal-bg{
    position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;
    align-items:center;justify-content:center;z-index:1000;padding:20px
  }
  .reg-modal-bg.open{display:flex}
  .reg-modal{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-xl);padding:24px;width:100%;max-width:380px
  }
  .reg-modal h2{font-size:18px;font-weight:600;margin-bottom:8px;color:var(--ia-text)}
  .reg-modal .lede{color:var(--ia-text-dim);font-size:13px;margin-bottom:18px}
  .reg-modal-actions{display:flex;gap:8px;margin-top:18px}
  .reg-btn-secondary{
    flex:1;padding:11px;background:var(--ia-surface-2);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-weight:500;
    font-family:inherit;cursor:pointer
  }
  .reg-btn-secondary:hover{border-color:var(--ia-border-strong)}
  .reg-btn-primary{
    flex:1;padding:11px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-sm);font-size:13px;font-weight:600;
    font-family:inherit;cursor:pointer
  }
  .reg-btn-primary:hover:not(:disabled){filter:brightness(.93)}
  .reg-btn-primary:disabled{opacity:.4;cursor:not-allowed}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Layaways</h1>
    <p class="ia-page-subtitle">Goods held and money taken against them.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">{{-- MARKER-REG-MOBILE --}}
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  {{-- MARKER-LAYAWAY-TAB --}}
  <a href="{{ route('tenant.register.layaways.index') }}" class="reg-tab-link active">Layaways</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
  </div>
</div>

{{-- MARKER-LAYAWAY-TAB — legend. Two things here are invisible without being
     said: held stock is on the shelf but unsellable, and money taken is a
     liability rather than revenue until the goods leave. --}}
<div style="background:rgba(111,179,242,.07);border:0.5px solid rgba(111,179,242,.3);border-radius:8px;
            padding:11px 14px;font-size:12.5px;color:var(--ia-text-muted);margin-bottom:16px;line-height:1.55">
  <strong>Held stock is still on your shelf</strong> and still counts when you count it — it just
  cannot be sold to anyone else. <strong>Money taken is not revenue yet.</strong> It becomes a sale
  on the day the goods are handed over, dated that day.
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px">
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">OPEN PLANS</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">{{ $rows->count() }}</div>
  </div></div>
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">MONEY HELD</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">${{ number_format($heldCents / 100, 2) }}</div>
    <div style="font-size:11px;color:var(--ia-text-dim)">a liability, not revenue</div>
  </div></div>
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">STILL OWED</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">${{ number_format($owedCents / 100, 2) }}</div>
  </div></div>
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">NEEDS ATTENTION</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">
      {{ $overdue }}<span style="font-size:13px;color:var(--ia-text-dim)"> overdue</span>
      @if($readyNow)<span style="font-size:13px;color:var(--ia-text-dim)"> · {{ $readyNow }} ready</span>@endif
    </div>
  </div></div>
</div>

<div class="ia-card">
  @if($rows->isEmpty())
    <div class="ia-card-body" style="color:var(--ia-text-muted)">
      No open layaways. Start one from the register: add items, attach a customer, then
      <strong>Put on layaway</strong> in the tender window.
    </div>
  @else
    <table class="ia-table">
      <thead>
        <tr>
          <th>Plan</th><th>Customer</th><th>Items</th>
          <th style="text-align:right">Paid / total</th>
          <th>Next due</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $r)
          @php $p = $r['plan']; @endphp
          <tr style="cursor:pointer" onclick="window.location='{{ route('tenant.register.layaways.show', $p->id) }}'">
            <td>
              <strong>{{ $p->sale->sale_number ?: Str::substr($p->id, 0, 8) }}</strong>
              <div style="font-size:11.5px;color:var(--ia-text-dim)">{{ $p->created_at?->format('M j') }}</div>
            </td>
            <td>{{ $p->customer?->name ?: '—' }}</td>
            <td>
              {{ $p->sale->items->first()?->name_snapshot ?: '—' }}
              @if($p->sale->items->count() > 1)
                <div style="font-size:11.5px;color:var(--ia-text-dim)">+ {{ $p->sale->items->count() - 1 }} more</div>
              @endif
            </td>
            <td style="text-align:right">
              ${{ number_format($r['paid'] / 100, 2) }} / ${{ number_format($p->sale->total_cents / 100, 2) }}
              <div style="height:4px;background:var(--ia-surface-2);border-radius:99px;margin-top:5px;overflow:hidden;width:110px;margin-left:auto">
                <div style="height:100%;width:{{ min(100, $r['pct']) }}%;background:var(--ia-accent)"></div>
              </div>
            </td>
            <td>
              @if($r['ready'])<span style="color:var(--ia-text-dim)">—</span>
              @elseif($p->next_due_on)
                {{ $p->next_due_on->format('M j') }}
                @if($p->scheduled_amount_cents)
                  <div style="font-size:11.5px;color:var(--ia-text-dim)">${{ number_format($p->scheduled_amount_cents / 100, 2) }}</div>
                @endif
              @else<span style="color:var(--ia-text-dim)">—</span>@endif
            </td>
            <td>
              @if($r['overdue'])
                <span class="ia-badge" style="color:#f2777a;border-color:rgba(242,119,122,.4)">Overdue</span>
              @elseif($r['ready'])
                <span class="ia-badge" style="color:#6fb3f2;border-color:rgba(111,179,242,.4)">Ready to collect</span>
              @elseif($r['awaiting'])
                <span class="ia-badge ia-badge--amber">Awaiting arrival</span>
              @elseif($r['due_soon'])
                <span class="ia-badge ia-badge--amber">Due soon</span>
              @else
                <span class="ia-badge">On track</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
