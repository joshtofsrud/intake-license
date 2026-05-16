{{--
  Shared upsell modal partial.
  Usage:  @include('tenant.reports._upsell_modal', [
            'title' => 'Retail Reports',
            'pitch' => 'See sales by user, top SKUs, margin, dead-stock alerts.',
          ])
--}}
<div id="rep-upsell-backdrop" class="rep-upsell-backdrop open" onclick="if (event.target === this) closeRepUpsell()">
  <div class="rep-upsell-modal" role="dialog" aria-labelledby="rep-upsell-title">
    <button type="button" class="close" onclick="closeRepUpsell()" aria-label="Close">×</button>
    <div class="badge">Branded feature</div>
    <h2 id="rep-upsell-title">{{ $title ?? 'Reports' }}</h2>
    <p>{{ $pitch ?? 'Upgrade to Branded to unlock the full reports tier.' }}</p>
    <div class="cta-row">
      <a class="cta-primary" href="{{ route('tenant.team.index', ['subdomain' => tenant()->subdomain]) }}">Upgrade to Branded →</a>
      <button type="button" class="cta-secondary" onclick="closeRepUpsell()">Maybe later</button>
    </div>
  </div>
</div>
<script>
  function closeRepUpsell() {
    var el = document.getElementById('rep-upsell-backdrop');
    if (el) el.classList.remove('open');
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeRepUpsell();
  });
</script>
