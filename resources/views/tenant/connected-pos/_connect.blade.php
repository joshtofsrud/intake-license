{{-- MARKER-CONNECTED-POS-SECTION: no Connected POS yet --}}
<div class="ec-sec">
  <h3>Connect your outside POS</h3>
  @forelse($pos['available'] as $a)
    <div class="ec-row">
      <div><div class="t">{{ $a->displayLabel() }}</div><div class="d">You'll sign in there and approve Intake, then come straight back here. Nothing is copied until you ask for it.</div></div>
      <div class="ctl"><form method="POST" action="{{ route('tenant.cpos.connect', $a->code) }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Connect</button></form></div>
    </div>
  @empty
    <p class="ec-note">No outside POS can be connected right now. Tell us which one you use at <a class="cpx-lnk" href="mailto:{{ config('intake.support_email', 'hello@intake.works') }}?subject={{ rawurlencode('Connected POS') }}">{{ config('intake.support_email', 'hello@intake.works') }}</a> and we'll let you know when it's ready.</p>
  @endforelse
</div>
<div class="ec-sec">
  <h3>How you use it</h3>
  <p class="ec-note" style="margin-bottom:8px">Pick the one that fits. You can change it later.</p>
  <form method="POST" action="{{ route('tenant.cpos.use') }}" class="cpx-seg">
    @csrf
    <button type="submit" name="use" value="stay" class="{{ $pos['use'] === 'stay' ? 'on' : '' }}" @disabled($pos['use'] === 'stay')>Staying on my POS</button>
    <button type="submit" name="use" value="move" class="{{ $pos['use'] === 'move' ? 'on' : '' }}" @disabled($pos['use'] === 'move')>Moving to Intake</button>
  </form>
  <p class="ec-note" style="margin-top:8px">{{ $pos['use'] === 'move' ? 'You are moving to Intake: connect your current POS, bring in what you need step by step, then switch over.' : 'Your POS stays the till and the source of stock. Intake runs the rest and sends online orders back to it.' }}</p>
</div>
