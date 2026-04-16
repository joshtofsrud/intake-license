@extends('marketing.layout')
@section('title', 'Contact — Intake')

@push('styles')
<style>
.mk-contact-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:clamp(32px,5vw,72px);align-items:start}
.mk-contact-info h2{font-size:clamp(22px,3vw,34px);font-weight:700;letter-spacing:-.02em;margin-bottom:12px}
.mk-contact-info p{font-size:15px;color:var(--mk-muted);line-height:1.7;margin-bottom:28px}
.mk-contact-detail{display:flex;align-items:flex-start;gap:12px;margin-bottom:18px;font-size:14px;color:rgba(255,255,255,.65)}
.mk-contact-icon{width:32px;height:32px;background:var(--mk-accent-dim);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mk-contact-icon svg{width:15px;height:15px;stroke:var(--mk-accent);fill:none;stroke-width:1.5;stroke-linecap:round}
.mk-form-card{background:rgba(255,255,255,.03);border:0.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:28px}
label{display:block;font-size:12px;font-weight:500;color:var(--mk-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
input[type=text],input[type=email],textarea{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--mk-border2);border-radius:var(--mk-r);color:var(--mk-text);font-size:14px;font-family:inherit;transition:border-color .12s;margin-bottom:16px}
input:focus,textarea:focus{outline:none;border-color:var(--mk-accent)}
textarea{resize:vertical;min-height:120px}
.mk-success{background:rgba(190,242,100,.08);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--mk-r);padding:14px 18px;font-size:14px;color:var(--mk-accent);margin-bottom:20px}
@media(max-width:760px){.mk-contact-grid{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
<section class="mk-section" style="border-bottom:none">
  <div class="mk-container">
    <div class="mk-contact-grid">
      <div class="mk-contact-info">
        <div class="mk-eyebrow">Contact</div>
        <h2>We'd love to hear from you</h2>
        <p>Got a question about pricing, need help getting set up, or want to discuss a custom integration? Send us a message.</p>
        <div class="mk-contact-detail">
          <div class="mk-contact-icon">
            <svg viewBox="0 0 16 16"><rect x="1.5" y="3" width="13" height="10" rx="1.5"/><path d="M1.5 5l6.5 5 6.5-5"/></svg>
          </div>
          <div>
            <div style="font-weight:500;margin-bottom:2px">Email</div>
            <div>hello@intake.works</div>
          </div>
        </div>
        <div class="mk-contact-detail">
          <div class="mk-contact-icon">
            <svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
          </div>
          <div>
            <div style="font-weight:500;margin-bottom:2px">Response time</div>
            <div>Usually within one business day</div>
          </div>
        </div>
      </div>

      <div class="mk-form-card">
        @if(session('contact_success'))
          <div class="mk-success">Thanks! We'll get back to you within one business day.</div>
        @endif
        <form method="POST" action="{{ route('marketing.contact') }}">
          @csrf
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label>Name *</label>
              <input type="text" name="name" value="{{ old('name') }}" required placeholder="Jane Smith">
            </div>
            <div>
              <label>Email *</label>
              <input type="email" name="email" value="{{ old('email') }}" required placeholder="jane@shop.com">
            </div>
          </div>
          <label>Message *</label>
          <textarea name="message" required placeholder="How can we help?">{{ old('message') }}</textarea>
          @if($errors->any())
            <p style="font-size:13px;color:#F09595;margin-bottom:12px">{{ $errors->first() }}</p>
          @endif
          <button type="submit" class="mk-btn mk-btn--primary" style="width:100%;justify-content:center">
            Send message
          </button>
        </form>
      </div>
    </div>
  </div>
</section>
@endsection
