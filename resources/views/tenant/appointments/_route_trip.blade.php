{{-- MARKER-PATCH-514 — Round trip: the appointment's route legs.
     Renders nothing unless this appointment has pickup/delivery rows. --}}
@php
  $rtLegs = \App\Models\Tenant\TenantDelivery::where('tenant_id', $appointment->tenant_id)
      ->where('appointment_id', $appointment->id)
      ->orderBy('scheduled_at')
      ->get();
  $rtPickup   = $rtLegs->firstWhere('type', 'pickup');
  $rtDropoff  = $rtLegs->firstWhere('type', 'dropoff');
@endphp
@if($rtLegs->isNotEmpty() || $appointment->need_by)
<div style="margin-top:12px;border:0.5px solid var(--ia-border);border-radius:12px;background:var(--ia-surface-2);padding:13px 15px">
  <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);margin-bottom:9px">Round trip</div>

  <div style="display:flex;flex-direction:column;gap:7px">
    @if($rtPickup)
      <div style="display:flex;align-items:center;gap:9px;font-size:12.5px">
        <span style="font-size:9.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#7bbf6a;background:rgba(123,191,106,.14);border-radius:99px;padding:2px 9px;width:64px;text-align:center;flex:none">Pickup</span>
        <span style="font-weight:600">{{ tlocal_datetime($rtPickup->scheduled_at, 'D M j · g:i A') }}</span>
        <span style="color:var(--ia-text-muted);font-size:11.5px">
          @if($rtPickup->status === 'completed') · picked up {{ tlocal($rtPickup->completed_at ?? $rtPickup->scheduled_at, 'g:i A') }}
          @elseif($rtPickup->status === 'cancelled') · cancelled
          @else · scheduled @endif
        </span>
        <a href="{{ route('tenant.deliveries.index') }}" style="margin-left:auto;font-size:11px;color:var(--ia-accent);text-decoration:none">Route →</a>
      </div>
    @endif

    @if($appointment->need_by)
      <div style="display:flex;align-items:center;gap:9px;font-size:12.5px">
        <span style="font-size:9.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ia-accent);background:var(--ia-accent-soft);border-radius:99px;padding:2px 9px;width:64px;text-align:center;flex:none">Need by</span>
        <span style="font-weight:600">{{ \Carbon\Carbon::parse($appointment->need_by)->format('D M j') }}</span>
        <span style="color:var(--ia-text-muted);font-size:11.5px">· customer deadline</span>
      </div>
    @endif

    <div style="display:flex;align-items:center;gap:9px;font-size:12.5px">
      <span style="font-size:9.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ia-accent);background:var(--ia-accent-soft);border-radius:99px;padding:2px 9px;width:64px;text-align:center;flex:none">Deliver</span>
      @if($rtDropoff)
        <span style="font-weight:600">{{ tlocal_datetime($rtDropoff->scheduled_at, 'D M j · g:i A') }}</span>
        <span style="color:var(--ia-text-muted);font-size:11.5px">· {{ $rtDropoff->status }}</span>
      @else
        <span style="color:var(--ia-text-muted)">window proposed when work is marked complete</span>
      @endif
    </div>
  </div>
</div>
@endif
