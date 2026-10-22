{{-- MARKER-CLASSES-P9: the studio's waiver, asked for at booking until signed --}}
@if(! empty($waiver))
  <div class="cl-waiver" style="margin:14px 0 16px">
    <details @if($errors->has('booking') && str_contains($errors->first('booking'), 'waiver')) open @endif>
      <summary style="cursor:pointer;font-size:13.5px;font-weight:600">Read the waiver</summary>
      <div style="font-size:13px;line-height:1.6;opacity:.8;white-space:pre-line;max-height:240px;overflow:auto;margin-top:8px;padding:10px 12px;background:var(--p-surface);border-radius:var(--p-r)">{{ $waiver->body }}</div>
    </details>
    <label class="co-check" style="margin:10px 0 10px"><input type="checkbox" name="waiver_agree" value="1" class="js-waiver-agree" @checked(old('waiver_agree'))> <span>I've read the waiver and agree to it.</span></label>
    <div class="ac-field" style="margin-bottom:0">
      <label class="ac-label">Your full name</label>
      <input type="text" name="waiver_name" class="ac-input js-waiver-name" maxlength="160" value="{{ old('waiver_name') }}" autocomplete="name">
    </div>
  </div>
@endif
