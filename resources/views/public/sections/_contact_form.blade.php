<section class="p-section">
  <div class="p-container">
    <div style="max-width:580px;margin:0 auto">
      @if(!empty($c['heading']))
        <div class="p-section-head-wrap" style="text-align:center">
          <h2 class="p-section-heading">{{ $c['heading'] }}</h2>
        </div>
      @endif

      @if(session('contact_success'))
        <div class="p-flash p-flash--success" style="text-align:center">
          Thanks! We'll be in touch soon.
        </div>
      @endif

      <form method="POST" action="/contact">
        @csrf

        @if($errors->any())
          <div class="p-flash p-flash--error">
            {{ $errors->first() }}
          </div>
        @endif

        <div class="p-form-group">
          <label class="p-label">Name *</label>
          <input type="text" name="name" class="p-input" value="{{ old('name') }}" required placeholder="Your name">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="p-form-group">
            <label class="p-label">Email *</label>
            <input type="email" name="email" class="p-input" value="{{ old('email') }}" required placeholder="you@example.com">
          </div>
          @if($c['show_phone'] ?? true)
            <div class="p-form-group">
              <label class="p-label">Phone</label>
              <input type="tel" name="phone" class="p-input" value="{{ old('phone') }}" placeholder="+1 (555) 000-0000">
            </div>
          @endif
        </div>
        <div class="p-form-group">
          <label class="p-label">Message *</label>
          <textarea name="message" class="p-input" rows="5" required
            placeholder="How can we help you?" style="resize:vertical">{{ old('message') }}</textarea>
        </div>
        <button type="submit" class="p-btn p-btn--primary" style="width:100%;justify-content:center">
          Send message
        </button>
      </form>
    </div>
  </div>
</section>
