{{-- MARKER-INVEST-CONFIRM — expects $confirmTitle and $confirmBody, and only
     renders when one of the success flags is in the session. --}}
@php
  $confirmShow = session('invest_lead_ok') || session('invest_request_ok') || session('commit_ok');
@endphp

@if($confirmShow)
<div class="cf-back" id="cf-back">
  <div class="cf" role="dialog" aria-modal="true" aria-labelledby="cf-t">
    <h3 id="cf-t">{{ $confirmTitle ?? 'Recorded.' }}</h3>
    <p>{{ $confirmBody ?? 'Thanks — that is with me.' }}</p>
    <button type="button" class="btn" id="cf-ok">Close</button>
  </div>
</div>

<script>
(function () {
  var back = document.getElementById('cf-back');
  var ok   = document.getElementById('cf-ok');
  if (!back || !ok) { return; }

  function close() { back.remove(); }

  ok.addEventListener('click', close);
  back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
  ok.focus();
})();
</script>
@endif
