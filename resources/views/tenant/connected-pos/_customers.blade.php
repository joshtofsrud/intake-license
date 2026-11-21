{{-- MARKER-POS-CUSTOMERS-IN: customers coming in, with live progress and Stop/Resume --}}
@php
  $pc = $pos['conn'];
  $cs = (array) ($pc->customer_sync ?? []);
  $csRunning = (bool) ($cs['running'] ?? false);
@endphp
<div class="ec-sec">
  <h3>Customers from your Connected POS</h3>
  <form method="POST" action="{{ route('tenant.cpos.customers') }}" class="ec-row">
    @csrf
    <div><div class="t">Bring in customers <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>The first time, every customer is read; after that, new and changed ones come in about every minute. Each is matched to an Intake customer by email, then phone, and only blank fields are filled. Nothing you entered in Intake is overwritten. No one is opted in to marketing, and "do not email" there means opted out here.</span></div></div>
    <div class="ctl">
      <label class="tg"><input type="hidden" name="customers_in" value="0"><input type="checkbox" name="customers_in" value="1" @checked($pc->customers_in)><i></i></label>
      <button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Save</button>
    </div>
  </form>
  @if($pc->customers_in || $cs)
    <div class="ec-row">
      <div>
        <div class="t">@if($csRunning) Bringing in customers… @elseif($cs['stopped'] ?? false) Stopped @elseif(! empty($cs['error'])) The last run stopped with an error @elseif(! empty($cs['last_done'])) Up to date @else Not started @endif</div>
        @if($csRunning)<div class="cp-live" style="margin-top:6px"><span class="cp-dot"></span>Reading page <span data-live="customers.pages">{{ number_format($cs['pages'] ?? 0) }}</span></div>@endif
        <div class="av-sum" style="margin-top:8px">
          <div>Read<b data-live="customers.seen">{{ number_format($cs['seen'] ?? 0) }}</b></div>
          <div>Matched<b data-live="customers.matched">{{ number_format($cs['matched'] ?? 0) }}</b></div>
          <div>New<b data-live="customers.created">{{ number_format($cs['created'] ?? 0) }}</b></div>
          <div>Filled in<b data-live="customers.filled">{{ number_format($cs['filled'] ?? 0) }}</b></div>
          <div class="w">Skipped <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Deleted in your Connected POS, or no name, email or phone to know them by.</span><b data-live="customers.skipped">{{ number_format($cs['skipped'] ?? 0) }}</b></div>
        </div>
        @if(! empty($cs['error']))<div class="d" style="color:#F26D6D">{{ $cs['error'] }}</div>@endif
      </div>
      <div class="ctl">
        @if($csRunning)
          <form method="POST" action="{{ route('tenant.cpos.customers_stop') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Stop</button></form>
        @elseif($cs['stopped'] ?? false)
          <form method="POST" action="{{ route('tenant.cpos.customers_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume from page {{ number_format($cs['pages'] ?? 0) }}</button></form>
        @endif
        @if($pc->customers_in && ! $csRunning)
          <form method="POST" action="{{ route('tenant.cpos.customers_import') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Read every customer again</button></form>
        @endif
      </div>
    </div>
  @else
    <p class="ec-note">Not brought in yet. Turn it on and save to start; you'll see every customer counted here as they come in, and can stop and resume.</p>
  @endif
</div>
@include('tenant.connected-pos._live')
