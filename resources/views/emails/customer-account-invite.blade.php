<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,sans-serif;font-size:15px;line-height:1.6;color:#111">
  <p>Hi {{ $customer->first_name }},</p>

  @if (! empty($selfSignup))
  <p>Thanks for creating an account with {{ $tenant->name }}. It's where you can see your
     upcoming bookings, past orders, rentals, and messages with us — all in one place.</p>

  <p>Confirm this is your email, then create your password:</p>
  @else
  <p>{{ $tenant->name }} set up an account for you. It's where you can see your
     upcoming bookings, past orders, rentals, and messages with us — all in one place.</p>

  <p>Pick a password to finish setting it up:</p>
  @endif

  <p style="margin:26px 0">
    <a href="{{ $setupUrl }}"
       style="display:inline-block;padding:13px 26px;border-radius:8px;font-weight:600;
              text-decoration:none;background:{{ $accent }};background-image:linear-gradient({{ $accent }},{{ $accent }});color:{{ $accent_text }}">{{ ! empty($selfSignup) ? 'Confirm & create password' : 'Set up my account' }}</a>
  </p>

  <p style="font-size:13px;opacity:.6">This link expires in 60 minutes. If it does, ask us to send a new one —
     or use "Forgot password?" on the sign-in page.</p>

  <p style="font-size:13px;opacity:.6">If you {{ ! empty($selfSignup) ? "didn't ask for an account" : "weren't expecting this" }}, you can ignore it and nothing changes.</p>

  <p>— {{ $tenant->name }}</p>
</div>
