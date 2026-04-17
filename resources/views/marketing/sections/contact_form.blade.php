{{-- Contact form. Content: heading, show_phone, show_message --}}
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container" style="max-width:600px">
        @if(!empty($c['heading']))
            <h2 style="text-align:center;margin-bottom:32px">{{ $c['heading'] }}</h2>
        @endif

        @if(session('contact_success'))
            <div style="background:#D1FAE5;color:#065F46;padding:16px;border-radius:12px;margin-bottom:24px;text-align:center">
                ✓ Thanks — we'll be in touch shortly.
            </div>
        @endif

        <form method="POST" action="{{ route('marketing.contact.submit') }}" style="display:flex;flex-direction:column;gap:16px">
            @csrf

            <div>
                <label style="display:block;font-size:14px;font-weight:500;margin-bottom:6px">Name</label>
                <input type="text" name="name" required value="{{ old('name') }}" style="width:100%;padding:12px 14px;border:1px solid var(--mk-border);border-radius:8px;font-size:15px;font-family:inherit">
                @error('name') <p style="color:#EF4444;font-size:13px;margin-top:4px">{{ $message }}</p> @enderror
            </div>

            <div>
                <label style="display:block;font-size:14px;font-weight:500;margin-bottom:6px">Email</label>
                <input type="email" name="email" required value="{{ old('email') }}" style="width:100%;padding:12px 14px;border:1px solid var(--mk-border);border-radius:8px;font-size:15px;font-family:inherit">
                @error('email') <p style="color:#EF4444;font-size:13px;margin-top:4px">{{ $message }}</p> @enderror
            </div>

            @if($c['show_phone'] ?? false)
                <div>
                    <label style="display:block;font-size:14px;font-weight:500;margin-bottom:6px">Phone (optional)</label>
                    <input type="tel" name="phone" value="{{ old('phone') }}" style="width:100%;padding:12px 14px;border:1px solid var(--mk-border);border-radius:8px;font-size:15px;font-family:inherit">
                </div>
            @endif

            @if($c['show_message'] ?? true)
                <div>
                    <label style="display:block;font-size:14px;font-weight:500;margin-bottom:6px">Message</label>
                    <textarea name="message" rows="5" required style="width:100%;padding:12px 14px;border:1px solid var(--mk-border);border-radius:8px;font-size:15px;font-family:inherit;resize:vertical">{{ old('message') }}</textarea>
                    @error('message') <p style="color:#EF4444;font-size:13px;margin-top:4px">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="mk-btn mk-btn--primary" style="margin-top:8px">Send message</button>
        </form>
    </div>
</section>
