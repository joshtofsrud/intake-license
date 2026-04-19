@php
  // Hide "Powered by intake" when tenant is on Branded or Custom plan,
  // or when master admin has explicitly overridden branding.
  $showBranding = $currentTenant->show_intake_branding ?? true;
@endphp

@if($showBranding)
  <div class="ia-brand-footer">
    Powered by <a href="https://intake.works" target="_blank" rel="noopener">intake</a>
  </div>
@endif
