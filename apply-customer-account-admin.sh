#!/usr/bin/env bash
set -euo pipefail
# apply-customer-account-admin.sh — MARKER-CUST-ACCOUNT
# Staff can finally see and manage customer portal accounts:
#   - "Account" pill on the customer list (desktop + mobile) and detail page
#   - "Has portal account" / "No portal account" filters on the list
#   - Send account invite (password-less customer) and Send password reset
#     (existing account) — both email the SAME tokenized link the customer
#     reset flow already validates, so no new token plumbing and no
#     staff-chosen passwords ever exist
#   - Gated by a NEW capability customers.account_manage under the customers
#     section, so Roles & access can turn it on/off per staff level. The
#     roles editor renders capabilities generically, so the toggle appears
#     with no editor change.
# Deliberately NOT included: staff typing a new password for a customer. The
# emailed link keeps the password known only to the customer and leaves the
# reset auditable.

REG=app/Support/CapabilityRegistry.php
CTRL=app/Http/Controllers/Tenant/CustomerController.php
ROUTES=routes/web.php
LIST=resources/views/tenant/customers/index.blade.php
SHOW=resources/views/tenant/customers/show.blade.php
MAIL=app/Mail/CustomerAccountInvite.php
MAILV=resources/views/emails/customer-account-invite.blade.php

for f in "$REG" "$CTRL" "$ROUTES" "$LIST" "$SHOW"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-CUST-ACCOUNT" "$REG"; then
  echo "Already applied (MARKER-CUST-ACCOUNT present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- capability
python3 - "$REG" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            // ---- Scheduling ----"""
new = """            // ---- Customers ---- MARKER-CUST-ACCOUNT
            'customers.account_manage' => [
                'label'   => 'Manage customer portal accounts',
                'section' => 'customers',
                'desc'    => 'Send account invites and password reset links to customers.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],

            // ---- Scheduling ----"""
n = src.count(old)
if n != 1:
    print(f"FAIL capability: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   capability customers.account_manage")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- mailable
if [ -f "$MAIL" ]; then
  echo "ok   invite mailable already present"
else
  cat <<'EOF' > "$MAIL"
<?php

namespace App\Mail;

// MARKER-CUST-ACCOUNT — staff-initiated "set up your account" email. Carries
// the same token the customer reset flow validates, so one code path owns
// token checking and expiry.

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerAccountInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TenantCustomer $customer,
        public readonly string $token,
        public readonly Tenant $tenant
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            subject: 'Set up your account — ' . $this->tenant->name,
        );
    }

    public function content(): Content
    {
        $url = route('tenant.customer.reset', [
            'token' => $this->token,
            'email' => $this->customer->email,
        ]);

        return new Content(
            view: 'emails.customer-account-invite',
            with: [
                'tenant'   => $this->tenant,
                'customer' => $this->customer,
                'setupUrl' => $url,
                'accent'      => $this->tenant->accent_color ?? '#BEF264',
                'accent_text' => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
            ]
        );
    }
}
EOF
  echo "ok   invite mailable created"
fi

if [ -f "$MAILV" ]; then
  echo "ok   invite email view already present"
else
  cat <<'EOF' > "$MAILV"
{{-- MARKER-CUST-ACCOUNT --}}
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,sans-serif;font-size:15px;line-height:1.6;color:#111">
  <p>Hi {{ $customer->first_name }},</p>

  <p>{{ $tenant->name }} set up an account for you. It's where you can see your
     upcoming bookings, past orders, rentals, and messages with us — all in one place.</p>

  <p>Pick a password to finish setting it up:</p>

  <p style="margin:26px 0">
    <a href="{{ $setupUrl }}"
       style="display:inline-block;padding:13px 26px;border-radius:8px;font-weight:600;
              text-decoration:none;background:{{ $accent }};color:{{ $accent_text }}">Set up my account</a>
  </p>

  <p style="font-size:13px;opacity:.6">This link expires in 60 minutes. If it does, ask us to send a new one —
     or use "Forgot password?" on the sign-in page.</p>

  <p style="font-size:13px;opacity:.6">If you weren't expecting this, you can ignore it and nothing changes.</p>

  <p>— {{ $tenant->name }}</p>
</div>
EOF
  echo "ok   invite email view created"
fi

# ---------------------------------------------------------------- controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) list filters, alongside the existing VIP / business pseudo-sorts
edit("""        // MARKER-BIZ-LIST — same pattern as VIPs. Also the practical route to
        // finding records where a business was typed into a person's name.
        if ($sort === 'businesses_only') {""",
"""        // MARKER-CUST-ACCOUNT — same pseudo-sort pattern. A portal account
        // is exactly "has set a password".
        if ($sort === 'has_account') {
            $q->whereNotNull('password');
        }
        if ($sort === 'no_account') {
            $q->whereNull('password');
        }

        // MARKER-BIZ-LIST — same pattern as VIPs. Also the practical route to
        // finding records where a business was typed into a person's name.
        if ($sort === 'businesses_only') {""",
"list account filters")

# 2) the two staff actions, appended before the final closing brace
tail = src.rstrip()
if not tail.endswith('}'):
    print("FAIL controller tail: file does not end with }"); sys.exit(1)
methods = '''
    /**
     * MARKER-CUST-ACCOUNT — email the customer a link to set (or reset) their
     * portal password. Staff never see or choose the password; this issues the
     * same token the customer-facing reset flow already validates.
     */
    public function sendAccountLink(Request $request, string $id)
    {
        abort_unless(auth('tenant')->user()?->can('customers.account_manage'), 403);

        $tenant   = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $id)->firstOrFail();

        if (blank($customer->email)) {
            return back()->with('error', 'This customer has no email address on file.');
        }

        $isInvite = $customer->password === null;

        $token = \\Illuminate\\Support\\Str::random(64);
        $customer->update([
            'password_reset_token'   => \\Illuminate\\Support\\Facades\\Hash::make($token),
            'password_reset_sent_at' => now(),
        ]);

        try {
            \\Illuminate\\Support\\Facades\\Mail::to($customer->email)->send(
                $isInvite
                    ? new \\App\\Mail\\CustomerAccountInvite($customer, $token, $tenant)
                    : new \\App\\Mail\\CustomerPasswordReset($customer, $token, $tenant)
            );
        } catch (\\Throwable $e) {
            \\Illuminate\\Support\\Facades\\Log::warning('customer account link send failed', [
                'customer_id' => $customer->id, 'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'The email could not be sent — check your email settings and try again.');
        }

        return back()->with('success', $isInvite
            ? 'Account invite sent to ' . $customer->email . '.'
            : 'Password reset link sent to ' . $customer->email . '.');
    }
}
'''
src = tail[:-1].rstrip('\n') + '\n' + methods
print("ok   sendAccountLink()")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- route
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            Route::post('/customers/{customerId}/contacts',                [TenantControllers\\CustomerController::class, 'storeContact'])->name('customers.contacts.store');"""
new = """            // MARKER-CUST-ACCOUNT — capability-gated inside the controller.
            Route::post('/customers/{id}/account-link',                    [TenantControllers\\CustomerController::class, 'sendAccountLink'])->name('customers.account_link');
            Route::post('/customers/{customerId}/contacts',                [TenantControllers\\CustomerController::class, 'storeContact'])->name('customers.contacts.store');"""
n = src.count(old)
if n != 1:
    print(f"FAIL route: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   route customers.account_link")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- list view
python3 - "$LIST" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

edit("""    'businesses_only' => 'Businesses only', // MARKER-BIZ-LIST""",
"""    'businesses_only' => 'Businesses only', // MARKER-BIZ-LIST
    'has_account'  => 'Has portal account',   // MARKER-CUST-ACCOUNT
    'no_account'   => 'No portal account',    // MARKER-CUST-ACCOUNT""",
"list filter options")

edit("""              @if($c->isBusiness())
                <span class="biz-pill">Business</span>
                @if($c->tax_exempt)<span class="biz-pill exempt">Tax exempt</span>@endif
              @endif""",
"""              @if($c->isBusiness())
                <span class="biz-pill">Business</span>
                @if($c->tax_exempt)<span class="biz-pill exempt">Tax exempt</span>@endif
              @endif
              {{-- MARKER-CUST-ACCOUNT --}}
              @if($c->password)<span class="biz-pill acct-pill" title="Has a portal account">Account</span>@endif""",
"desktop row pill")

edit("""        <div class="cust-card-meta">
          @if($lastSvc)Last service {{ $lastSvc }} · @endif
          Added {{ $c->created_at->format('M j, Y') }}
        </div>""",
"""        <div class="cust-card-meta">
          @if($lastSvc)Last service {{ $lastSvc }} · @endif
          Added {{ $c->created_at->format('M j, Y') }}
          @if($c->password) · Account @endif{{-- MARKER-CUST-ACCOUNT --}}
        </div>""",
"mobile row marker")

edit("""  .biz-pill.exempt{border-color:rgba(232,163,61,.4);color:#E8A33D}""",
"""  .biz-pill.exempt{border-color:rgba(232,163,61,.4);color:#E8A33D}
  /* MARKER-CUST-ACCOUNT */
  .biz-pill.acct-pill{border-color:var(--ia-accent);color:var(--ia-accent)}""",
"pill CSS")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- detail view
python3 - "$SHOW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """    <p class="ia-page-subtitle">
      {{ $customer->email }}
      @if($customer->phone) · {{ $customer->phone }} @endif
      · Added {{ $customer->created_at->format('M j, Y') }}
    </p>
  </div>"""
new = """    <p class="ia-page-subtitle">
      {{ $customer->email }}
      @if($customer->phone) · {{ $customer->phone }} @endif
      · Added {{ $customer->created_at->format('M j, Y') }}
    </p>
    {{-- MARKER-CUST-ACCOUNT — portal account state + staff actions. The
         button emails a link; staff never set a customer's password. --}}
    @php
      $caHasAccount = $customer->password !== null;
      $caCanManage  = (bool) auth('tenant')->user()?->can('customers.account_manage');
    @endphp
    <div class="cust-acct-row">
      <span class="cust-acct-badge {{ $caHasAccount ? 'on' : '' }}">
        {{ $caHasAccount ? 'Portal account' : 'No portal account' }}
      </span>
      @if($caCanManage && $customer->email)
        <form method="POST" action="{{ route('tenant.customers.account_link', $customer->id) }}"
              onsubmit="return confirm('{{ $caHasAccount ? 'Email a password reset link to ' : 'Email an account invite to ' }}{{ $customer->email }}?')">
          @csrf
          <button type="submit" class="cust-acct-btn">
            {{ $caHasAccount ? 'Send password reset' : 'Send account invite' }}
          </button>
        </form>
      @endif
    </div>
  </div>"""
n = src.count(old)
if n != 1:
    print(f"FAIL detail block: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   detail account row")

# The detail page renders NO flash today, so a success/error would vanish.
# .ia-flash is the themed component (base.css + theme-c overrides).
old_flash = """{{-- Header — VIP-DESKTOP-INTEGRATION v1 --}}
<div class="ia-page-head">"""
new_flash = """{{-- MARKER-CUST-ACCOUNT — this page had no flash render at all --}}
@if(session('success'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>
@endif

{{-- Header — VIP-DESKTOP-INTEGRATION v1 --}}
<div class="ia-page-head">"""
n = src.count(old_flash)
if n != 1:
    print(f"FAIL detail flash: anchor found {n} times"); sys.exit(1)
src = src.replace(old_flash, new_flash, 1)
print("ok   detail flash render")

# CSS — appended to the view's own style block, matching its neighbours
old_css = """/* Customer list — small ★ next to VIP customer names */"""
new_css = """/* MARKER-CUST-ACCOUNT — portal account state on the detail header */
.cust-acct-row{display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap}
.cust-acct-badge{display:inline-flex;align-items:center;font-size:10px;font-weight:800;letter-spacing:.07em;
  text-transform:uppercase;border-radius:100px;padding:3px 9px;border:.5px solid var(--ia-border);color:var(--ia-text-dim)}
.cust-acct-badge.on{border-color:var(--ia-accent);color:var(--ia-accent)}
.cust-acct-btn{background:none;border:0;padding:0;font:inherit;font-size:12px;font-weight:600;
  color:var(--ia-text-muted);cursor:pointer;border-bottom:1px solid currentColor}
.cust-acct-btn:hover{color:var(--ia-text)}

/* Customer list — small ★ next to VIP customer names */"""
n = src.count(old_css)
if n != 1:
    print(f"FAIL detail css: anchor found {n} times"); sys.exit(1)
src = src.replace(old_css, new_css, 1)
print("ok   detail account CSS")

open(path, 'w').write(src)
PY

php -l "$REG"
php -l "$CTRL"
php -l "$MAIL"

echo ""
echo "SUCCESS — apply-customer-account-admin applied."
echo "The Roles & access editor renders capabilities generically, so the new"
echo "toggle appears under Customers with no editor change. Deploy's optimize"
echo "covers route + view cache."
