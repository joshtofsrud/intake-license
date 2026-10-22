@extends('layouts.tenant.app')
@php $pageTitle = 'Class Settings'; @endphp
{{-- MARKER-CLASSES-P8: every class setting on one screen, one Save --}}

@push('styles')
<style>
.cl-subnav{display:flex;gap:2px;margin-bottom:20px;border-bottom:0.5px solid var(--ia-border)}
.cl-subnav-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;text-decoration:none;transition:color var(--ia-t),border-color var(--ia-t)}
.cl-subnav-tab:hover{color:var(--ia-text)}
.cl-subnav-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.cl-input,.cl-select{padding:7px 10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none;transition:border var(--ia-t);font-family:inherit}
.cl-input:focus,.cl-select:focus{border-color:var(--ia-accent)}
.cl-select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.4)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.cs-wrap{max-width:860px}
.cs-sec{display:grid;grid-template-columns:220px 1fr;gap:10px 28px;padding:22px 0;border-bottom:0.5px solid var(--ia-border)}
.cs-sec h2{font-size:14px;font-weight:600;margin:0 0 4px}
.cs-sec p.cs-help{font-size:12.5px;color:var(--ia-text-muted);line-height:1.5;margin:0}
.cs-line{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px;font-size:13.5px;margin-bottom:12px}
.cs-line:last-child{margin-bottom:0}
.cs-num{width:84px}
.cs-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 0;border-bottom:0.5px solid var(--ia-border)}
.cs-row:last-child{border-bottom:none}
.cs-row-t{font-size:13.5px}
.cs-row-s{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.cs-flag{font-size:12px;color:#EF4444}
.cs-save{display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:18px 0}
.cs-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.5;max-width:620px}
@media(max-width:720px){ .cs-sec{grid-template-columns:1fr} }
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Classes</h1>
    <p class="ia-page-subtitle">Your studio's rules for booking, cancelling and the waitlist, and the emails customers get.</p>
  </div>
</div>

<div class="cl-subnav-wrap"><nav class="cl-subnav">
  <a href="{{ route('tenant.classes.templates') }}" class="cl-subnav-tab">Templates</a>
  <a href="{{ route('tenant.classes.sessions') }}" class="cl-subnav-tab">Schedule</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-subnav-tab">Memberships</a>
  <a href="{{ route('tenant.classes.packs') }}" class="cl-subnav-tab">Packs</a>
  <a href="{{ route('tenant.classes.reports') }}" class="cl-subnav-tab">Reports</a>
  <a href="{{ route('tenant.classes.settings') }}" class="cl-subnav-tab is-active">Settings</a>
</nav></div>

@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:12px">{{ session('success') }}</div>@endif
@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:12px">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ route('tenant.classes.settings.save') }}" class="cs-wrap">
  @csrf

  <section class="cs-sec">
    <div><h2>Booking</h2><p class="cs-help">When customers can book online. Leave a box blank for no limit.</p></div>
    <div>
      <div class="cs-line">Customers can book up to
        <input type="number" min="0" max="365" name="book_ahead_days" value="{{ old('book_ahead_days', $rules['book_ahead_days']) }}" class="cl-input cs-num" placeholder="any"> days ahead.</div>
      <div class="cs-line">Online booking closes
        <input type="number" min="0" max="10080" name="book_cutoff_minutes" value="{{ old('book_cutoff_minutes', $rules['book_cutoff_minutes']) }}" class="cl-input cs-num" placeholder="0"> minutes before class.</div>
      <div class="cs-line">Booking online without a pass:
        <select name="class_online_dropin" class="cl-select">
          @foreach(\App\Services\ClassOnlineSaleService::DROPIN_MODES as $k => $label)
            <option value="{{ $k }}" @selected($onlineDropin === $k)>{{ $label }}</option>
          @endforeach
        </select></div>
      @unless($cardsOn)
        <div class="cs-line cs-row-s">Card payments aren't set up, so everyone pays at the studio for now. <a href="{{ route('tenant.settings.payments.index') }}" style="color:var(--ia-text)">Set up payments</a></div>
      @endunless
    </div>
  </section>

  <section class="cs-sec">
    <div><h2>Cancelling</h2><p class="cs-help">What happens when a customer cancels online. Staff can always cancel and give credits back.</p></div>
    <div>
      <div class="cs-line">Free to cancel until
        <input type="number" min="0" max="720" name="cancel_cutoff_hours" value="{{ old('cancel_cutoff_hours', $rules['cancel_cutoff_hours']) }}" class="cl-input cs-num" placeholder="start"> hours before class.</div>
      <div class="cs-line">After that:
        <select name="after_cutoff" class="cl-select">
          @foreach(\App\Services\ClassRules::AFTER_CUTOFF as $k => $label)
            <option value="{{ $k }}" @selected($rules['after_cutoff'] === $k)>{{ $label }}</option>
          @endforeach
        </select></div>
      <div class="cs-line">Paid online and cancelled in time:
        <select name="online_refund" class="cl-select">
          <option value="">Choose one</option>
          @foreach(\App\Services\ClassRules::ONLINE_REFUND as $k => $label)
            <option value="{{ $k }}" @selected($rules['online_refund'] === $k)>{{ $label }}</option>
          @endforeach
        </select>
        @if(empty($rules['online_refund']))<span class="cs-flag">Not set. Nothing is refunded automatically until you choose.</span>@endif
      </div>
    </div>
  </section>

  <section class="cs-sec">
    <div><h2>Waitlist</h2><p class="cs-help">Filling spots that open up. People move in by themselves, in order, when someone cancels.</p></div>
    <div>
      <div class="cs-row" style="padding-top:0">
        <div><div class="cs-row-t">Full classes have a waitlist</div><div class="cs-row-s">Off: a full class just says it's full</div></div>
        <input type="hidden" name="waitlist_on" value="{{ $rules['waitlist_on'] ? 1 : 0 }}">
        <button type="button" class="ia-toggle {{ $rules['waitlist_on'] ? 'on' : '' }}" data-cs-toggle><span class="ia-toggle-sr">Waitlist</span></button>
      </div>
      <div class="cs-line" style="margin-top:12px">Up to
        <input type="number" min="1" max="500" name="waitlist_max" value="{{ old('waitlist_max', $rules['waitlist_max']) }}" class="cl-input cs-num" placeholder="any"> people can wait.</div>
      <div class="cs-line">Stop moving people in
        <input type="number" min="0" max="720" name="waitlist_stop_hours" value="{{ old('waitlist_stop_hours', $rules['waitlist_stop_hours']) }}" class="cl-input cs-num" placeholder="never"> hours before class.</div>
    </div>
  </section>

  <section class="cs-sec">
    <div><h2>Repeating classes</h2><p class="cs-help">How far ahead a repeating class's dates are created. More are added each day.</p></div>
    <div>
      <div class="cs-line">Create classes
        <input type="number" min="1" max="26" name="publish_weeks" value="{{ old('publish_weeks', $rules['publish_weeks']) }}" class="cl-input cs-num"> weeks ahead.</div>
    </div>
  </section>

  <section class="cs-sec">
    <div><h2>Messages</h2><p class="cs-help">Emails to customers. To change the wording, use <a href="{{ route('tenant.communication.index') }}" style="color:var(--ia-text)">Communication</a>.</p></div>
    <div>
      @foreach(\App\Services\ClassMessages::LABELS as $key => [$label, $desc])
        <div class="cs-row">
          <div>
            <div class="cs-row-t">{{ $label }}</div>
            <div class="cs-row-s">
              @if($key === 'class_reminder')
                <input type="number" min="1" max="48" name="reminder_hours" value="{{ old('reminder_hours', $rules['reminder_hours']) }}" class="cl-input" style="width:60px;padding:3px 6px;font-size:12px"> hours before class
              @else
                {{ $desc }}
              @endif
            </div>
          </div>
          <input type="hidden" name="notify[{{ $key }}]" value="{{ $messages[$key] ? 1 : 0 }}">
          <button type="button" class="ia-toggle {{ $messages[$key] ? 'on' : '' }}" data-cs-toggle><span class="ia-toggle-sr">{{ $label }}</span></button>
        </div>
      @endforeach
    </div>
  </section>

  <div class="cs-save">
    <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
    <span class="cs-legend">Rules apply to every booking and cancel from now on, including classes already booked. Staff are never held to them.</span>
  </div>
</form>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-cs-toggle]').forEach(function (b) {
  b.addEventListener('click', function () {
    var on = !b.classList.contains('on');
    b.classList.toggle('on', on);
    b.previousElementSibling.value = on ? '1' : '0';
  });
});
</script>
@endpush
