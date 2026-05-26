@extends('layouts.tenant.app')
@php $pageTitle = 'Email suppressions'; @endphp

{{-- MARKER-PATCH-147 — tenant suppression list --}}

@push('styles')
<style>
  .sup-tabs { display: inline-flex; background: var(--ia-surface-2); border-radius: var(--ia-r-md); padding: 3px; font-size: 12.5px; margin-bottom: 14px; }
  .sup-tabs a {
    padding: 5px 14px;
    border-radius: 6px;
    color: var(--ia-text-muted);
    text-decoration: none;
  }
  .sup-tabs a.active {
    background: var(--ia-surface);
    color: var(--ia-text);
    font-weight: 500;
    box-shadow: 0 1px 2px rgba(0,0,0,.06);
  }
  .sup-tabs a:hover { color: var(--ia-text); }

  .sup-row {
    display: grid;
    grid-template-columns: 1.6fr 130px 130px 1fr auto;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--ia-border);
    align-items: center;
    font-size: 13px;
  }
  .sup-row:last-child { border-bottom: none; }
  .sup-row:hover { background: var(--ia-surface-2); }

  .sup-row-head {
    background: var(--ia-surface-2);
    font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em;
    color: var(--ia-text-muted); font-weight: 500;
    padding: 9px 16px;
  }
  .sup-row-head:hover { background: var(--ia-surface-2); }

  .sup-pill {
    display: inline-block;
    padding: 2px 9px;
    font-size: 11px;
    border-radius: 999px;
    line-height: 1.6;
  }
  .sup-pill.bad  { background: rgba(192,57,43,.12);  color: var(--ia-bad, #C0392B); }
  .sup-pill.warn { background: rgba(198,130,16,.14); color: var(--ia-warn, #C68210); }
  .sup-pill.muted { background: var(--ia-surface-2); color: var(--ia-text-muted); }

  .sup-platform-badge {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    font-size: 10px;
    border-radius: 3px;
    background: rgba(48,102,176,.12);
    color: var(--ia-info, #3066B0);
    vertical-align: middle;
  }

  .sup-empty {
    padding: 48px 24px;
    text-align: center;
    color: var(--ia-text-muted);
  }
  .sup-empty .icon { font-size: 32px; opacity: .25; margin-bottom: 8px; }

  .sup-add-block {
    background: var(--ia-surface-2);
    border-radius: var(--ia-r-md);
    padding: 14px 16px;
    margin-top: 14px;
    display: none;
  }
  .sup-add-block.open { display: block; }

  .sup-mono { font-family: var(--ia-font-mono); font-size: 12.5px; }
</style>
@endpush

@section('content')
<div class="ia-page">
  <div class="ia-page-header">
    <div>
      <h1 class="ia-page-title">Email suppressions</h1>
      <div class="ia-page-sub">Addresses that won't receive your mail. Automatically populated from bounces, complaints, and unsubscribes.</div>
    </div>
    <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('sup-add').classList.toggle('open')">
      + Manually suppress
    </button>
  </div>

  @if(session('success'))
    <div class="ia-flash ia-flash--ok" style="margin-bottom: 12px;">{{ session('success') }}</div>
  @endif
  @if(session('error'))
    <div class="ia-flash ia-flash--err" style="margin-bottom: 12px;">{{ session('error') }}</div>
  @endif

  <div class="ia-card">
    <div class="sup-tabs">
      <a href="{{ route('tenant.suppressions.index', ['tab' => 'all']) }}"
         class="{{ $tab === 'all' ? 'active' : '' }}">All · {{ $counts['all'] }}</a>
      <a href="{{ route('tenant.suppressions.index', ['tab' => 'bounced']) }}"
         class="{{ $tab === 'bounced' ? 'active' : '' }}">Bounced · {{ $counts['bounced'] }}</a>
      <a href="{{ route('tenant.suppressions.index', ['tab' => 'complained']) }}"
         class="{{ $tab === 'complained' ? 'active' : '' }}">Complained · {{ $counts['complained'] }}</a>
      <a href="{{ route('tenant.suppressions.index', ['tab' => 'other']) }}"
         class="{{ $tab === 'other' ? 'active' : '' }}">Unsub'd / Manual · {{ $counts['other'] }}</a>
    </div>

    <div id="sup-add" class="sup-add-block">
      <div style="font-size: 13px; font-weight: 500; margin-bottom: 8px;">Manually suppress an address</div>
      <form method="POST" action="{{ route('tenant.suppressions.store') }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;">
        @csrf
        <div style="flex: 1; min-width: 240px;">
          <label class="ia-label" style="margin-bottom: 4px;">Email</label>
          <input type="email" name="email" class="ia-input" required placeholder="customer@example.com">
        </div>
        <div style="flex: 2; min-width: 240px;">
          <label class="ia-label" style="margin-bottom: 4px;">Notes (optional)</label>
          <input type="text" name="notes" class="ia-input" placeholder="Why you're suppressing this">
        </div>
        <button type="submit" class="ia-btn ia-btn--primary">Suppress</button>
      </form>
    </div>

    @if($rows->isEmpty())
      <div class="sup-empty">
        <div class="icon">✓</div>
        <div style="font-size: 14px; font-weight: 500; color: var(--ia-text);">No suppressed addresses</div>
        <div style="font-size: 12px; margin-top: 4px;">When a customer's mail bounces, complains, or unsubscribes, they show up here.</div>
      </div>
    @else
      <div class="ia-row-list" style="border: 1px solid var(--ia-border); border-radius: var(--ia-r-md); overflow: hidden; margin-top: 14px;">
        <div class="sup-row sup-row-head">
          <div>Email</div>
          <div>Reason</div>
          <div>Suppressed</div>
          <div>Diagnostic</div>
          <div></div>
        </div>
        @foreach($rows as $row)
          <div class="sup-row">
            <div>
              <span class="sup-mono">{{ $row->email }}</span>
              @if(is_null($row->tenant_id))
                <span class="sup-platform-badge" title="Suppressed platform-wide — not just on your list">platform</span>
              @endif
            </div>
            <div>
              @if($row->reason === 'bounce')
                <span class="sup-pill bad">{{ $row->subtype === 'Permanent' ? 'Hard bounce' : 'Bounced' }}</span>
              @elseif($row->reason === 'complaint')
                <span class="sup-pill warn">Spam complaint</span>
              @elseif($row->reason === 'unsubscribe')
                <span class="sup-pill muted">Unsubscribed</span>
              @else
                <span class="sup-pill muted">{{ ucfirst($row->reason) }}</span>
              @endif
            </div>
            <div style="color: var(--ia-text-muted); font-size: 12px;">
              {{ $row->suppressed_at?->format('M j, Y') ?? '—' }}
            </div>
            <div style="font-size: 11.5px; color: var(--ia-text-dim); font-family: var(--ia-font-mono);">
              {{ \Illuminate\Support\Str::limit($row->diagnostic ?? $row->notes ?? '—', 60) }}
            </div>
            <div style="text-align: right;">
              @if($row->reason === 'complaint' || is_null($row->tenant_id))
                <span style="font-size: 11.5px; color: var(--ia-text-dim);">Permanent</span>
              @else
                <form method="POST" action="{{ route('tenant.suppressions.destroy', ['id' => $row->id]) }}" style="display: inline;">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="ia-btn ia-btn--ghost" style="font-size: 11.5px; padding: 4px 10px;"
                          onclick="return confirm('Remove {{ $row->email }} from your suppression list? They\'ll receive future mail again.')">
                    Remove
                  </button>
                </form>
              @endif
            </div>
          </div>
        @endforeach
      </div>

      <div style="margin-top: 14px;">
        {{ $rows->withQueryString()->links() }}
      </div>
    @endif

    <div style="margin-top: 18px; padding: 12px 14px; background: rgba(48,102,176,.06); border-radius: var(--ia-r-md); font-size: 12.5px; color: var(--ia-text-muted); line-height: 1.6;">
      <strong style="color: var(--ia-text);">How this works.</strong>
      When a customer's email bounces (mailbox doesn't exist) or they mark your mail as spam, they're automatically added here so you don't accidentally send them more — which would hurt your shop's sender reputation.
      Complaints are permanent. Bounces and manual entries can be removed once you've fixed the underlying issue.
      Addresses marked <span class="sup-platform-badge">platform</span> are suppressed across all of Intake, usually because they bounced from multiple shops.
    </div>
  </div>
</div>
@endsection
