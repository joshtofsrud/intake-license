{{-- Contact form. Content: heading, show_phone, show_message --}}
<style>
    .mk-cf-input {
        width: 100%;
        padding: 12px 14px;
        background: rgba(255,255,255,.03);
        border: 0.5px solid var(--mk-border);
        border-radius: 8px;
        font-size: 15px;
        font-family: inherit;
        color: var(--mk-text);
        transition: border-color .12s, background .12s;
    }
    .mk-cf-input:focus {
        outline: none;
        border-color: rgba(190,242,100,.5);
        background: rgba(255,255,255,.05);
    }
    .mk-cf-input::placeholder { color: var(--mk-dim); }
    .mk-cf-label {
        display: block;
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 6px;
        color: var(--mk-text);
        letter-spacing: .02em;
    }
    .mk-cf-error { color: #F87171; font-size: 12px; margin-top: 4px; }
    textarea.mk-cf-input { resize: vertical; font-family: inherit; }
</style>

<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container" style="max-width:580px">
        @if(!empty($c['heading']))
            <h2 class="mk-section-title" style="text-align:center;margin-bottom:32px">{{ $c['heading'] }}</h2>
        @endif

        @if(session('contact_success'))
            <div style="
                background: rgba(190,242,100,.08);
                border: 0.5px solid rgba(190,242,100,.3);
                color: var(--mk-accent);
                padding:14px 16px;
                border-radius:10px;
                margin-bottom:20px;
                text-align:center;
                font-size:14px;
            ">
                ✓ Thanks — we'll be in touch shortly.
            </div>
        @endif

        <form method="POST" action="{{ route('marketing.contact.submit') }}" style="display:flex;flex-direction:column;gap:14px">
            @csrf

            <div>
                <label class="mk-cf-label">Name</label>
                <input type="text" name="name" required value="{{ old('name') }}" class="mk-cf-input">
                @error('name') <p class="mk-cf-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mk-cf-label">Email</label>
                <input type="email" name="email" required value="{{ old('email') }}" class="mk-cf-input">
                @error('email') <p class="mk-cf-error">{{ $message }}</p> @enderror
            </div>

            @if($c['show_phone'] ?? false)
                <div>
                    <label class="mk-cf-label">Phone <span style="opacity:.5;font-weight:400">(optional)</span></label>
                    <input type="tel" name="phone" value="{{ old('phone') }}" class="mk-cf-input">
                </div>
            @endif

            @if($c['show_message'] ?? true)
                <div>
                    <label class="mk-cf-label">Message</label>
                    <textarea name="message" rows="5" required class="mk-cf-input">{{ old('message') }}</textarea>
                    @error('message') <p class="mk-cf-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="mk-btn mk-btn--primary" style="margin-top:4px;width:100%">Send message</button>
        </form>
    </div>
</section>
