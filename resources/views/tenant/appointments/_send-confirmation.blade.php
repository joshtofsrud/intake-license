{{-- MARKER-SEND-CONFIRMATION — shared by show + show-multi-asset.
     Expects: $appointment, $confirmCanEmail, $confirmCanSms,
              $confirmSentAt, $confirmChannels, $confirmFailed --}}
@php
  // Blade comments break inside @php, so these are // comments by rule.
  // Label describes the LAST send, not what is possible now.
  $sc_chLabel = null;
  if (!empty($confirmChannels)) {
      $sc_hasSms   = in_array('sms', $confirmChannels, true);
      $sc_hasEmail = in_array('email', $confirmChannels, true);
      $sc_chLabel  = $sc_hasSms && $sc_hasEmail ? 'text and email' : ($sc_hasSms ? 'text' : 'email');
  }
  $sc_any = $confirmCanEmail || $confirmCanSms;
@endphp

<div class="sc-wrap" style="width:100%">
  @if($sc_any)
    <button type="button" class="ia-btn ia-btn--secondary" id="sc-open" style="width:100%">
      &#9993; {{ $confirmSentAt ? 'Resend confirmation' : 'Send confirmation' }}
    </button>

    <div id="sc-choices" style="display:none;margin-top:8px">
      @if($confirmCanSms)
        <button type="button" class="ia-btn ia-btn--secondary sc-go" data-ch="sms" style="width:100%;margin-bottom:6px">Text</button>
      @endif
      @if($confirmCanEmail)
        <button type="button" class="ia-btn ia-btn--secondary sc-go" data-ch="email" style="width:100%;margin-bottom:6px">Email</button>
      @endif
      @if($confirmCanSms && $confirmCanEmail)
        <button type="button" class="ia-btn ia-btn--secondary sc-go" data-ch="both" style="width:100%;margin-bottom:6px">Text and email</button>
      @endif
      <button type="button" class="ia-btn" id="sc-cancel" style="width:100%">Never mind</button>
    </div>
  @endif

  <div id="sc-note" style="font-size:11.5px;line-height:1.55;opacity:.55;margin-top:8px;text-align:center">
    @if($confirmSentAt)
      Customer notified {{ tlocal_datetime($confirmSentAt, 'M j, g:i A') }}@if($sc_chLabel) · {{ $sc_chLabel }}@endif
    @elseif($confirmFailed)
      Last confirmation failed to send.
    @elseif($sc_any)
      Customer has not been notified.
    @else
      No way to reach this customer — add an email or phone, or turn on booking confirmations in settings.
    @endif
  </div>
</div>

@if($sc_any)
<script>
(function () {
  var NOTIFY = @json(route('tenant.appointments.notify', ['id' => $appointment->id]));
  var open   = document.getElementById('sc-open');
  var box    = document.getElementById('sc-choices');
  var note   = document.getElementById('sc-note');
  if (!open) { return; }

  open.addEventListener('click', function () {
    var showing = box.style.display !== 'none';
    box.style.display = showing ? 'none' : '';
    open.style.display = showing ? '' : 'none';
  });

  document.getElementById('sc-cancel').addEventListener('click', function () {
    box.style.display = 'none';
    open.style.display = '';
  });

  document.querySelectorAll('.sc-go').forEach(function (b) {
    b.addEventListener('click', function () {
      var ch = b.getAttribute('data-ch');
      var channels = ch === 'both' ? ['sms', 'email'] : [ch];
      document.querySelectorAll('.sc-go').forEach(function (x) { x.disabled = true; });
      b.textContent = 'Sending…';

      var meta = document.querySelector('meta[name="csrf-token"]');
      fetch(NOTIFY, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({ channels: channels })
      })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        box.style.display = 'none';
        open.style.display = '';
        open.innerHTML = '\u2709 Resend confirmation';
        // Queued, not delivered — say so rather than claiming a send that
        // the worker has not attempted yet. The note firms up on reload,
        // where it reads the actual log row.
        note.textContent = (j && j.ok)
          ? 'Queued just now — reload to see the delivery record.'
          : 'Could not queue that. Try again.';
      })
      .catch(function () {
        document.querySelectorAll('.sc-go').forEach(function (x) { x.disabled = false; });
        note.textContent = 'Network error — nothing was sent.';
      });
    });
  });
})();
</script>
@endif
