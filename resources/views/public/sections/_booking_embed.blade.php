<section class="p-section" id="book">
  <div class="p-container">
    @if(!empty($c['heading']))
      <div class="p-section-head-wrap" style="text-align:center">
        <h2 class="p-section-heading">{{ $c['heading'] }}</h2>
      </div>
    @endif
    {{--
      Full Livewire booking form will be embedded here.
      For now, redirect to the dedicated /book page.
    --}}
    <div style="text-align:center;padding:48px 0;border:1.5px dashed rgba(0,0,0,.12);border-radius:var(--p-r-lg)">
      <p style="font-size:16px;opacity:.5;margin-bottom:20px">
        Online booking
      </p>
      <a href="/book" class="p-btn p-btn--primary">Book an appointment</a>
    </div>
  </div>
</section>
