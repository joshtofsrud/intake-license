@extends('layouts.tenant.app')
@php $pageTitle = 'Campaigns'; @endphp

@push('styles')
<style>
.cm-new-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 18px;
  margin-bottom: 20px;
}
.cm-new-form { display: flex; gap: 10px; align-items: stretch; }
.cm-new-form input {
  flex: 1;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  color: var(--ia-text);
  padding: 10px 14px;
  font-size: 13px;
  font-family: inherit;
}
.cm-new-form input:focus { outline: none; border-color: var(--ia-accent); }

.cm-group { margin-bottom: 28px; }
.cm-group-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px;
}
.cm-group-title {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  font-weight: 600;
  opacity: .5;
}
.cm-group-count {
  font-size: 11px; opacity: .35;
}

.cm-list { display: flex; flex-direction: column; gap: 6px; }
.cm-row {
  display: grid;
  grid-template-columns: 1fr auto auto auto;
  gap: 16px;
  align-items: center;
  padding: 14px 16px;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  text-decoration: none;
  color: inherit;
  transition: background .1s;
}
.cm-row:hover { background: var(--ia-hover); }

.cm-row-name { font-size: 14px; font-weight: 500; }
.cm-row-meta { font-size: 11px; opacity: .45; margin-top: 2px; }
.cm-row-stat { font-size: 12px; opacity: .6; text-align: right; min-width: 80px; }
.cm-row-stat-value { font-weight: 600; color: var(--ia-text); }

.cm-status-pill {
  font-size: 10px;
  padding: 3px 9px;
  border-radius: 999px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.cm-status-pill.draft     { background: var(--ia-hover); color: var(--ia-text-muted); }
.cm-status-pill.scheduled { background: rgba(251,191,36,.12); color: #FCD34D; }
.cm-status-pill.sending   { background: rgba(96,165,250,.12); color: #93C5FD; }
.cm-status-pill.sent      { background: var(--ia-accent-soft); color: var(--ia-text); }

.cm-empty {
  padding: 14px 16px;
  border: 0.5px dashed var(--ia-border);
  border-radius: var(--ia-r-md);
  font-size: 13px;
  opacity: .4;
  text-align: center;
}

@media (max-width: 700px) {
  .cm-row { grid-template-columns: 1fr; gap: 8px; }
  .cm-row-stat { text-align: left; }
  .cm-new-form { flex-direction: column; }
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Campaigns</h1>
    <p class="ia-page-subtitle">Send broadcasts and automated follow-ups to your customer list.</p>
  </div>
</div>

{{-- Flash messages --}}
@if(session('success'))
  <div class="ia-alert ia-alert--success" style="margin-bottom:16px;padding:10px 14px;background:var(--ia-accent-soft);border:0.5px solid var(--ia-accent);border-radius:var(--ia-r-md);font-size:13px">
    {{ session('success') }}
  </div>
@endif
@if(session('error'))
  <div class="ia-alert ia-alert--error" style="margin-bottom:16px;padding:10px 14px;background:rgba(239,68,68,.1);border:0.5px solid rgba(239,68,68,.4);border-radius:var(--ia-r-md);font-size:13px;color:#FCA5A5">
    {{ session('error') }}
  </div>
@endif

{{-- New campaign form --}}
<div class="cm-new-card">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:8px">
    Start a new campaign
  </div>
  <form method="POST" action="{{ route('tenant.campaigns.store') }}" class="cm-new-form">
    @csrf
    <input type="text" name="name" placeholder="e.g. Spring tune-up reminder" required>
    <button type="submit" class="ia-btn ia-btn--primary">Create draft</button>
  </form>
  <p style="font-size:11px;opacity:.4;margin-top:8px">
    You have {{ $customerCount }} {{ Str::plural('customer', $customerCount) }} in your list.
  </p>
</div>

{{-- Drafts --}}
<div class="cm-group">
  <div class="cm-group-head">
    <span class="cm-group-title">Drafts</span>
    <span class="cm-group-count">{{ $groups['draft']->count() }}</span>
  </div>
  @if($groups['draft']->isEmpty())
    <div class="cm-empty">No drafts. Create one above to get started.</div>
  @else
    <div class="cm-list">
      @foreach($groups['draft'] as $c)
        <a href="{{ route('tenant.campaigns.show', $c->id) }}" class="cm-row">
          <div>
            <div class="cm-row-name">{{ $c->name }}</div>
            <div class="cm-row-meta">Updated {{ $c->updated_at->diffForHumans() }}</div>
          </div>
          <span class="cm-status-pill draft">Draft</span>
          <div class="cm-row-stat"><span class="cm-row-stat-value">—</span><br>recipients</div>
          <div style="opacity:.3;font-size:16px">›</div>
        </a>
      @endforeach
    </div>
  @endif
</div>

{{-- Scheduled / sending --}}
<div class="cm-group">
  <div class="cm-group-head">
    <span class="cm-group-title">Scheduled &amp; sending</span>
    <span class="cm-group-count">{{ $groups['scheduled']->count() }}</span>
  </div>
  @if($groups['scheduled']->isEmpty())
    <div class="cm-empty">Nothing scheduled.</div>
  @else
    <div class="cm-list">
      @foreach($groups['scheduled'] as $c)
        <a href="{{ route('tenant.campaigns.show', $c->id) }}" class="cm-row">
          <div>
            <div class="cm-row-name">{{ $c->name }}</div>
            <div class="cm-row-meta">
              @if($c->scheduled_at)
                Scheduled {{ $c->scheduled_at->format('M j · g:ia') }}
              @else
                Sending now…
              @endif
            </div>
          </div>
          <span class="cm-status-pill {{ $c->status }}">{{ ucfirst($c->status) }}</span>
          <div class="cm-row-stat"><span class="cm-row-stat-value">{{ $c->total_recipients }}</span><br>recipients</div>
          <div style="opacity:.3;font-size:16px">›</div>
        </a>
      @endforeach
    </div>
  @endif
</div>

{{-- Sent --}}
<div class="cm-group">
  <div class="cm-group-head">
    <span class="cm-group-title">Sent</span>
    <span class="cm-group-count">{{ $groups['sent']->count() }}</span>
  </div>
  @if($groups['sent']->isEmpty())
    <div class="cm-empty">No completed campaigns yet.</div>
  @else
    <div class="cm-list">
      @foreach($groups['sent'] as $c)
        <a href="{{ route('tenant.campaigns.show', $c->id) }}" class="cm-row">
          <div>
            <div class="cm-row-name">{{ $c->name }}</div>
            <div class="cm-row-meta">Sent {{ $c->sent_at?->diffForHumans() }}</div>
          </div>
          <span class="cm-status-pill sent">Sent</span>
          <div class="cm-row-stat">
            <span class="cm-row-stat-value">{{ $c->total_sent }}</span> / {{ $c->total_recipients }}<br>delivered
          </div>
          <div style="opacity:.3;font-size:16px">›</div>
        </a>
      @endforeach
    </div>
  @endif
</div>

@endsection
