{{-- MARKER-EMAIL-FIRST-SIGNUP: a customer's own reset email. It used to borrow
     the staff one, which told customers about their "staff account". --}}
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,sans-serif;font-size:15px;line-height:1.6;color:#111">
  <p>Hi {{ $customer->first_name ?: 'there' }},</p>

  @if (! empty($alreadyHasAccount))
  <p>Someone (hopefully you) tried to create an account at {{ $tenant->name }} with this email,
     but you already have one. Sign in with your password, or if you've forgotten it, choose a new one here:</p>
  @else
  <p>You asked to reset your password for your {{ $tenant->name }} account. Choose a new one here:</p>
  @endif

  <p style="margin:26px 0">
    <a href="{{ $resetUrl }}"
       style="display:inline-block;padding:13px 26px;border-radius:8px;font-weight:600;
              text-decoration:none;background:{{ $accent }};background-image:linear-gradient({{ $accent }},{{ $accent }});color:{{ $accent_text }}">Choose a new password</a>
  </p>

  <p style="font-size:13px;opacity:.6">This link expires in 60 minutes. If you didn't ask for this, you can ignore it and your password won't change.</p>

  <p>— {{ $tenant->name }}</p>
</div>
