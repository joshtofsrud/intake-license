@extends('layouts.tenant.app')
@php $pageTitle = $campaign->name; @endphp

@push('styles')
<style>
.cm-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
}
.cm-back {
  font-size: 12px;
  opacity: .5;
  text-decoration: none;
  color: inherit;
  margin-bottom: 8px;
  display: inline-block;
}
.cm-back:hover { opacity: .8; }

.cm-grid {
  display: grid;
  grid-template-columns: 1fr 280px;
  gap: 20px;
  align-items: start;
}

.cm-vars {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 16px;
  padding: 12px 14px;
  background: var(--ia-surface-2);
  border-radius: var(--ia-r-md);
}
.cm-var {
  font-size: 11px;
  font-family: var(--ia-font-mono);
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 4px;
  padding: 3px 8px;
  cursor: pointer;
  color: var(--ia-text-muted);
  transition: all .1s;
}
.cm-var:hover { border-color: var(--ia-accent); color: var(--ia-text); }

.cm-sidebar { display: flex; flex-direction: column; gap: 12px; }
.cm-sidebar-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 14px 16px;
}
.cm-sidebar-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  opacity: .4;
  font-weight: 600;
  margin-bottom: 8px;
}

.cm-audience-select {
  width: 100%;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  color: var(--ia-text);
  padding: 9px 12px;
  font-size: 13px;
  font-family: inherit;
}
.cm-audience-select:focus { outline: none; border-color: var(--ia-accent); }

.cm-stat { display: flex; justify-content: space-between; padding: 6px 0; font-size: 12px; }
.cm-stat-label { opacity: .5; }
.cm-stat-value { font-weight: 600; }

.cm-send-btn {
  width: 100%;
  padding: 12px;
  font-size: 13px;
  font-weight: 600;
}

@media (max-width: 900px) {
  .cm-grid { grid-template-columns: 1fr; }
}
</style>
@endpush

@section('content')

<a href="{{ route('tenant.campaigns.index') }}" class="cm-back">← Back to campaigns</a>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $campaign->name }}</h1>
    <p class="ia-page-subtitle">
      @if($campaign->status === 'draft')
        Draft — not yet sent
      @elseif($campaign->status === 'sending')
        Sending now…
      @elseif($campaign->status === 'sent')
        Sent {{ $campaign->sent_at?->diffForHumans() }}
      @else
        {{ ucfirst($campaign->status) }}
      @endif
    </p>
  </div>
</div>

{{-- Flash messages --}}
@if(session('success'))
  <div style="margin-bottom:16px;padding:10px 14px;background:var(--ia-accent-soft);border:0.5px solid var(--ia-accent);border-radius:var(--ia-r-md);font-size:13px">
    {{ session('success') }}
  </div>
@endif
@if(session('error'))
  <div style="margin-bottom:16px;padding:10px 14px;background:rgba(239,68,68,.1);border:0.5px solid rgba(239,68,68,.4);border-radius:var(--ia-r-md);font-size:13px;color:#FCA5A5">
    {{ session('error') }}
  </div>
@endif
@if($errors->any())
  <div style="margin-bottom:16px;padding:10px 14px;background:rgba(239,68,68,.1);border:0.5px solid rgba(239,68,68,.4);border-radius:var(--ia-r-md);font-size:13px;color:#FCA5A5">
    @foreach($errors->all() as $err)
      {{ $err }}<br>
    @endforeach
  </div>
@endif

<div class="cm-grid">

  {{-- LEFT: Composer --}}
  <div>
    <div class="ia-card">

      {{-- Variable chips (same chr() trick as email tab) --}}
      <div style="font-size:11px;opacity:.4;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;font-weight:600">
        Variables
      </div>
      @php($OBR = str_repeat(chr(123), 2))
      @php($CBR = str_repeat(chr(125), 2))
      <div class="cm-vars">
        @foreach(['first_name', 'last_name', 'shop_name'] as $var)
          @php($token = $OBR . $var . $CBR)
          <span class="cm-var" onclick="cmInsertVar(@js($token))" title="Click to insert">{{ $token }}</span>
        @endforeach
      </div>

      <form method="POST" action="{{ route('tenant.campaigns.update', $campaign->id) }}" id="cm-form">
        @csrf @method('PATCH')

        <div class="ia-form-group">
          <label class="ia-form-label">Campaign name <span class="ia-required">*</span></label>
          <input type="text" name="name" class="ia-input"
            value="{{ old('name', $campaign->name) }}"
            required
            {{ $campaign->status !== 'draft' ? 'readonly' : '' }}>
        </div>

        <div class="ia-form-group">
          <label class="ia-form-label">Subject line <span class="ia-required">*</span></label>
          <input type="text" name="subject" id="cm-subject" class="ia-input"
            value="{{ old('subject', $campaign->subject) }}"
            placeholder="e.g. It's time for your spring tune-up, {{ $OBR }}first_name{{ $CBR }}"
            required
            {{ $campaign->status !== 'draft' ? 'readonly' : '' }}>
        </div>

        <div class="ia-form-group">
          <label class="ia-form-label">Body (HTML supported)</label>
          <textarea name="body" id="cm-body" class="ia-input"
            style="min-height:280px;font-family:var(--ia-font-mono);font-size:12px;resize:vertical"
            rows="14"
            {{ $campaign->status !== 'draft' ? 'readonly' : '' }}>{{ old('body', $campaign->body_html) }}</textarea>
        </div>

        <input type="hidden" name="segment" id="cm-segment" value="{{ $campaign->targeting['segment'] ?? 'all' }}">

        @if($campaign->status === 'draft')
          <div style="display:flex;gap:10px;align-items:center">
            <button type="submit" class="ia-btn ia-btn--primary">Save draft</button>
          </div>
        @endif
      </form>
    </div>
  </div>

  {{-- RIGHT: Sidebar (audience + send + stats) --}}
  <div class="cm-sidebar">

    {{-- Audience --}}
    <div class="cm-sidebar-card">
      <div class="cm-sidebar-label">Audience</div>
      @if($campaign->status === 'draft')
        <select class="cm-audience-select" onchange="cmUpdateSegment(this.value)">
          @foreach($segments as $value => $label)
            <option value="{{ $value }}" {{ ($campaign->targeting['segment'] ?? 'all') === $value ? 'selected' : '' }}>
              {{ $label }}
            </option>
          @endforeach
        </select>
        <p style="font-size:11px;opacity:.45;margin-top:8px;line-height:1.4">
          Changes save automatically when you save the draft.
        </p>
      @else
        <div style="font-size:13px">{{ $segments[$campaign->targeting['segment'] ?? 'all'] ?? 'All customers' }}</div>
      @endif
    </div>

    {{-- Send --}}
    @if($campaign->status === 'draft')
      <div class="cm-sidebar-card">
        <div class="cm-sidebar-label">Ready to send?</div>
        <p style="font-size:12px;opacity:.55;line-height:1.5;margin-bottom:12px">
          This will queue the campaign for delivery. Once sent, content cannot be edited.
        </p>
        <form method="POST" action="{{ route('tenant.campaigns.send', $campaign->id) }}"
          onsubmit="return confirm('Send this campaign to the selected audience? This cannot be undone.');">
          @csrf
          <button type="submit" class="ia-btn ia-btn--primary cm-send-btn">Send now</button>
        </form>
      </div>
    @endif

    {{-- Stats --}}
    @if($campaign->status !== 'draft')
      <div class="cm-sidebar-card">
        <div class="cm-sidebar-label">Performance</div>
        <div class="cm-stat">
          <span class="cm-stat-label">Recipients</span>
          <span class="cm-stat-value">{{ $campaign->total_recipients }}</span>
        </div>
        <div class="cm-stat">
          <span class="cm-stat-label">Delivered</span>
          <span class="cm-stat-value">{{ $campaign->total_sent }}</span>
        </div>
        <div class="cm-stat">
          <span class="cm-stat-label">Opened</span>
          <span class="cm-stat-value">
            {{ $campaign->total_opened }}
            @if($campaign->total_sent > 0)
              <span style="opacity:.5;font-weight:400">({{ round($campaign->total_opened / $campaign->total_sent * 100) }}%)</span>
            @endif
          </span>
        </div>
        <div class="cm-stat">
          <span class="cm-stat-label">Clicked</span>
          <span class="cm-stat-value">
            {{ $campaign->total_clicked }}
            @if($campaign->total_sent > 0)
              <span style="opacity:.5;font-weight:400">({{ round($campaign->total_clicked / $campaign->total_sent * 100) }}%)</span>
            @endif
          </span>
        </div>
      </div>
    @endif

  </div>
</div>

@endsection

@push('scripts')
<script>
// Insert a variable token into whichever field (subject or body) had focus most recently.
function cmInsertVar(varStr) {
  var subject = document.getElementById('cm-subject');
  var body    = document.getElementById('cm-body');
  var last    = window._cmLastFocus;
  var target  = (last === subject || last === body) ? last : body;
  if (!target || target.readOnly) return;

  var start = target.selectionStart, end = target.selectionEnd;
  target.value = target.value.substring(0, start) + varStr + target.value.substring(end);
  target.selectionStart = target.selectionEnd = start + varStr.length;
  target.focus();
}

document.addEventListener('focusin', function(e) {
  if (e.target && e.target.id && /^cm-(subject|body)$/.test(e.target.id)) {
    window._cmLastFocus = e.target;
  }
});

// Update hidden segment input when audience select changes (gets saved on next draft save)
function cmUpdateSegment(value) {
  var input = document.getElementById('cm-segment');
  if (input) input.value = value;
}
</script>
@endpush
