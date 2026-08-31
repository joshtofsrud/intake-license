{{-- MARKER-MANUAL-SAFE — the contribute block, invited-page version.
     Collapsed, below the commitment, and worded for someone who has decided
     against the round rather than someone still deciding. --}}
@php
  $cPresets = \App\Services\ContributionService::presets();
@endphp

<details class="sec" id="s-back">
  <summary>Not investing? You can still back it
    <span class="cap">&mdash; a contribution, not a smaller commitment</span></summary>
  <div class="body">
    <div class="support" style="border:0;padding:0;margin:0">
      <p>If the round isn't for you — wrong time, wrong size, wrong shape — but you want to put something
        behind the work anyway, you can. It's a separate thing entirely, and it is not a smaller version
        of investing.</p>

      <form method="POST" action="{{ route('invest.contribute') }}">
        @csrf
        <div class="hp"><input type="text" name="company_website" tabindex="-1" autocomplete="off"></div>

        <div class="amts">
          @foreach($cPresets as $preset)
            <button type="button" class="amt-btn" data-amt="{{ $preset }}">${{ number_format($preset) }}</button>
          @endforeach
          <input type="text" name="amount" id="c-amt" value="{{ old('amount') }}"
                 inputmode="decimal" placeholder="Other" required autocomplete="off"
                 aria-label="Amount in dollars">
        </div>
        @error('amount') <span class="cerr">{{ $message }}</span> @enderror

        <div class="fields">
          <input type="text" name="name" value="{{ old('name', $investor->name ?? '') }}"
                 placeholder="Your name" required maxlength="120">
          <input type="email" name="email" value="{{ old('email', $investor->email ?? '') }}"
                 placeholder="Email for the receipt" required maxlength="190">
          <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="Phone (optional)" maxlength="40">
        </div>

        <button class="btn" type="submit">Contribute</button>
      </form>

      <p class="fine"><b>This buys nothing, and that's the point.</b> No equity, no SAFE, no share of
        anything later, and no expectation of a return — it does not convert into the round and never
        becomes one. It is not a partial commitment and <b>it does not count toward the round</b>. Intake
        Inc is a for-profit company, so this isn't a charitable donation and isn't tax deductible. Card
        details are handled by Stripe and never touch this site.</p>
    </div>
  </div>
</details>

<script>
(function () {
  var field = document.getElementById('c-amt');
  var btns  = document.querySelectorAll('#s-back .amt-btn');
  if (!field || !btns.length) { return; }

  function withSymbol(v) {
    var d = String(v).replace(/[^0-9.]/g, '');
    return d ? '$' + d : '';
  }

  function mark(v) {
    var n = parseFloat(String(v).replace(/[^0-9.]/g, ''));
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('on', parseFloat(btns[i].dataset.amt) === n);
    }
  }

  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener('click', function () {
      field.value = withSymbol(this.dataset.amt);
      mark(this.dataset.amt);
    });
  }

  field.addEventListener('input', function () { mark(field.value); });
})();
</script>
