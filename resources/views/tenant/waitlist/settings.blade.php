@extends('layouts.tenant.app')
@php $pageTitle = 'Waitlist settings'; @endphp

@push('styles')
<style>
.wls-group{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px 24px;margin-bottom:16px}
.wls-group-title{font-size:14px;font-weight:500;margin-bottom:4px}
.wls-group-sub{font-size:12.5px;color:var(--ia-text-muted);margin-bottom:16px}
.wls-field{margin-bottom:14px}
.wls-field:last-child{margin-bottom:0}
.wls-label{display:block;font-size:12px;color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.06em;font-weight:500;margin-bottom:6px}
.wls-input,.wls-select,.wls-textarea{width:100%;padding:9px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;font-family:inherit;outline:none}
.wls-input:focus,.wls-select:focus,.wls-textarea:focus{border-color:var(--ia-accent)}
.wls-textarea{min-height:80px;resize:vertical;line-height:1.5}
.wls-check{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:0.5px solid var(--ia-border)}
.wls-check:last-child{border-bottom:none}
.wls-check input[type=checkbox]{margin-top:2px;width:16px;height:16px;accent-color:var(--ia-accent);flex-shrink:0}
.wls-check-content{flex:1}
.wls-check-label{font-size:13.5px;font-weight:500;margin-bottom:2px;cursor:pointer}
.wls-check-sub{font-size:12px;color:var(--ia-text-muted);line-height:1.5}
.wls-row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:768px){.wls-row-2{grid-template-columns:1fr}}
.wls-actions{padding:20px 0;display:flex;gap:10px;justify-content:flex-end}
.wls-similar-row{display:grid;grid-template-columns:1fr 16px 1fr 40px;gap:10px;align-items:center;padding:8px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px}
.wls-similar-row:last-child{border-bottom:none}
.wls-arrow{color:var(--ia-text-muted);text-align:center}
.wls-similar-remove{color:var(--ia-text-muted);background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:4px}
.wls-similar-remove:hover{color:#EF4444;background:var(--ia-hover)}
.wls-plan-badge{display:inline-block;padding:2px 8px;font-size:11px;border-radius:4px;margin-left:8px;background:rgba(96,165,250,.12);color:#60A5FA;text-transform:uppercase;letter-spacing:.04em;font-weight:500}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Waitlist settings</h1>
    <p class="ia-page-subtitle">
      Waitlist feature:
      @if($tenant->hasWaitlistFeature())
        <span class="wls-plan-badge">Active on your plan</span>
      @else
        <span style="color:#EF4444">Not on your plan. Upgrade to enable.</span>
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.waitlist.index') }}" class="ia-btn">← Back to waitlist</a>
  </div>
</div>

@if(session('success'))
  <div style="padding:12px 16px;background:var(--ia-accent-soft);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--ia-r-md);margin-bottom:16px;font-size:13px;color:var(--ia-accent)">
    {{ session('success') }}
  </div>
@endif

@if($tenant->hasWaitlistFeature())
<form method="POST" action="{{ route('tenant.waitlist.settings.update') }}">
  @csrf
  @method('PATCH')

  <div class="wls-group">
    <div class="wls-group-title">Master switch</div>
    <div class="wls-group-sub">Turn waitlist features on or off for your customers.</div>
    <div class="wls-check">
      <input type="checkbox" id="enabled" name="enabled" value="1" {{ $settings->enabled ? 'checked' : '' }}>
      <div class="wls-check-content">
        <label class="wls-check-label" for="enabled">Enable waitlist</label>
        <div class="wls-check-sub">When off, customers can't join the waitlist and no notifications fire.</div>
      </div>
    </div>
  </div>

  <div class="wls-group">
    <div class="wls-group-title">Matching rules</div>
    <div class="wls-group-sub">When a slot opens, which waitlisted customers should be offered the spot?</div>
    <div class="wls-field">
      <label class="wls-label">Similar service rule</label>
      <select class="wls-select" name="similar_match_rule">
        <option value="exact_only" {{ $settings->similar_match_rule === 'exact_only' ? 'selected' : '' }}>Exact match only (strictest)</option>
        <option value="by_duration" {{ $settings->similar_match_rule === 'by_duration' ? 'selected' : '' }}>Any service with same duration</option>
        <option value="by_category" {{ $settings->similar_match_rule === 'by_category' ? 'selected' : '' }}>Any service in the same category</option>
        <option value="by_tenant_mapping" {{ $settings->similar_match_rule === 'by_tenant_mapping' ? 'selected' : '' }}>Custom mapping (set below)</option>
      </select>
    </div>
    <div class="wls-check">
      <input type="checkbox" id="exclude_first_time_customers" name="exclude_first_time_customers" value="1" {{ $settings->exclude_first_time_customers ? 'checked' : '' }}>
      <div class="wls-check-content">
        <label class="wls-check-label" for="exclude_first_time_customers">Exclude first-time customers from waitlist offers</label>
        <div class="wls-check-sub">If you're at capacity for new customers, enable this to only offer slots to existing customers.</div>
      </div>
    </div>
  </div>

  <div class="wls-group">
    <div class="wls-group-title">Triggers</div>
    <div class="wls-group-sub">Which events should open up a slot for waitlist matching?</div>
    <div class="wls-check">
      <input type="checkbox" id="include_cancellations" name="include_cancellations" value="1" {{ $settings->include_cancellations ? 'checked' : '' }}>
      <div class="wls-check-content">
        <label class="wls-check-label" for="include_cancellations">Customer cancellations</label>
        <div class="wls-check-sub">When someone cancels, the slot is offered to waitlisted customers.</div>
      </div>
    </div>
    <div class="wls-check">
      <input type="checkbox" id="include_manual_offers" name="include_manual_offers" value="1" {{ $settings->include_manual_offers ? 'checked' : '' }}>
      <div class="wls-check-content">
        <label class="wls-check-label" for="include_manual_offers">Manual offers</label>
        <div class="wls-check-sub">Let you open specific slots to the waitlist from the calendar view.</div>
      </div>
    </div>
  </div>

  <div class="wls-group">
    <div class="wls-group-title">Notifications</div>
    <div class="wls-group-sub">How waitlisted customers are notified when a slot opens.</div>
    <div class="wls-check">
      <input type="checkbox" id="notify_email" name="notify_email" value="1" {{ $settings->notify_email ? 'checked' : '' }}>
      <div class="wls-check-content">
        <label class="wls-check-label" for="notify_email">Email notifications</label>
      </div>
    </div>
    <div class="wls-check">
      <input type="checkbox" id="notify_sms" name="notify_sms" value="1" {{ $settings->notify_sms ? 'checked' : '' }}>
      <div class="wls-check-content">
        <label class="wls-check-label" for="notify_sms">SMS notifications</label>
        <div class="wls-check-sub">Requires Twilio integration. Customers without phone numbers won't receive SMS.</div>
      </div>
    </div>
  </div>

  <div class="wls-group">
    <div class="wls-group-title">Limits & copy</div>
    <div class="wls-field">
      <label class="wls-label">Max waitlist entries per customer (leave blank for unlimited)</label>
      <input class="wls-input" type="number" min="1" max="100" name="max_entries_per_customer" value="{{ $settings->max_entries_per_customer }}" placeholder="Unlimited">
    </div>
    <div class="wls-field">
      <label class="wls-label">Custom notification copy (optional)</label>
      <textarea class="wls-textarea" name="offer_copy_override" placeholder="Override the default &quot;offered to multiple customers&quot; blurb in emails/SMS.">{{ $settings->offer_copy_override }}</textarea>
    </div>
  </div>

  <div class="wls-actions">
    <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
  </div>
</form>

@if($settings->similar_match_rule === 'by_tenant_mapping')
<div class="wls-group" style="margin-top:20px">
  <div class="wls-group-title">Custom similar-service mappings</div>
  <div class="wls-group-sub">Define which services can substitute for which. Customer waitlisted for service A will also get offered service B if mapped.</div>

  <form method="POST" action="{{ route('tenant.waitlist.similar.add') }}" style="margin-bottom:14px">
    @csrf
    <div class="wls-similar-row">
      <select class="wls-select" name="service_item_id" required>
        <option value="">Waitlist for…</option>
        @foreach($services as $svc)
          <option value="{{ $svc->id }}">{{ $svc->name }}</option>
        @endforeach
      </select>
      <span class="wls-arrow">→</span>
      <select class="wls-select" name="substitutable_service_item_id" required>
        <option value="">Can also accept…</option>
        @foreach($services as $svc)
          <option value="{{ $svc->id }}">{{ $svc->name }}</option>
        @endforeach
      </select>
      <button type="submit" class="ia-btn ia-btn--sm">Add</button>
    </div>
  </form>

  @if($similarMap->count() > 0)
    <div style="border-top:0.5px solid var(--ia-border);padding-top:8px">
      @foreach($similarMap as $serviceId => $mappings)
        @foreach($mappings as $m)
          <div class="wls-similar-row">
            <div>{{ $m->serviceItem?->name ?? 'Deleted service' }}</div>
            <span class="wls-arrow">→</span>
            <div>{{ $m->substitutableServiceItem?->name ?? 'Deleted service' }}</div>
            <form method="POST" action="{{ route('tenant.waitlist.similar.remove', ['id' => $m->id]) }}">
              @csrf
              @method('DELETE')
              <button type="submit" class="wls-similar-remove" title="Remove mapping">×</button>
            </form>
          </div>
        @endforeach
      @endforeach
    </div>
  @else
    <div style="padding:14px;text-align:center;color:var(--ia-text-muted);font-size:12.5px">No mappings yet.</div>
  @endif
</div>
@endif

@endif

@endsection
