#!/usr/bin/env python3
"""Team member page: a Contact block that includes phone, and the same
Call / Text / Email tiles the customer page already uses.

Two gaps:
  * tenant_users.phone exists and is fillable, and staff SMS alerts send
    to it TODAY — but the field was never exposed anywhere in the admin,
    so a number could only be set outside the app.
  * There was no way to reach a teammate from their own page. Customers
    get contact tiles; staff got nothing.

Also renames the identity block and its button to say what they do:
"Contact" / "Save contact".
Run from repo root: python3 apply-team-contact-block.py
"""
import sys

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

VIEW = 'resources/views/tenant/team/show.blade.php'
CTRL = 'app/Http/Controllers/Tenant/TeamController.php'

# ============================================================
# 1) Controller — accept phone on update_account
# ============================================================
sub(CTRL,
    """            case 'update_account': {
                $data = $request->validate([
                    'name'  => ['required','string','max:255'],
                    'email' => ['required','email','max:255'],
                ]);""",
    """            case 'update_account': {
                // MARKER-TEAM-CONTACT — phone was fillable and already used by
                // staff SMS alerts, but no admin screen ever exposed it.
                // Loose validation on purpose: people type numbers however
                // they like; digits are stripped only for tel:/sms: links.
                $data = $request->validate([
                    'name'  => ['required','string','max:255'],
                    'email' => ['required','email','max:255'],
                    'phone' => ['nullable','string','max:32'],
                ]);
                $data['phone'] = ($data['phone'] ?? '') === '' ? null : $data['phone'];""",
    "controller: accept phone")

sub(CTRL,
    """                $member->update($data);
                return back()->with('success', 'Account updated.');""",
    """                $member->update($data);
                return back()->with('success', 'Contact details saved.');""",
    "controller: message wording")

# ============================================================
# 2) View — header contact tiles
# ============================================================
sub(VIEW,
    """  <div class="pd-actions">
    <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="display:inline">
      @csrf @method('PATCH')
      <input type="hidden" name="op" value="toggle_active">""",
    """  {{-- MARKER-TEAM-CONTACT — same tiles as the customer page, so there is one
       contact affordance in the product rather than two that drift. A tile
       with nothing behind it is disabled, not merely dead. --}}
  @php
    $tmPhoneDigits = $member->phone ? preg_replace('/[^0-9+]/', '', $member->phone) : '';
  @endphp
  <div class="pd-contact-tiles">
    <a href="{{ $tmPhoneDigits ? 'tel:' . $tmPhoneDigits : '#' }}"
       class="pd-tile {{ $tmPhoneDigits ? '' : 'is-disabled' }}"
       @if(!$tmPhoneDigits) aria-disabled="true" tabindex="-1" @endif
       title="{{ $tmPhoneDigits ? 'Call ' . $member->name : 'No phone number on file' }}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>
      </svg>
      <span class="pd-tile-label">Call</span>
    </a>
    <a href="{{ $tmPhoneDigits ? 'sms:' . $tmPhoneDigits : '#' }}"
       class="pd-tile {{ $tmPhoneDigits ? '' : 'is-disabled' }}"
       @if(!$tmPhoneDigits) aria-disabled="true" tabindex="-1" @endif
       title="{{ $tmPhoneDigits ? 'Text ' . $member->name : 'No phone number on file' }}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
      </svg>
      <span class="pd-tile-label">Text</span>
    </a>
    <a href="{{ $member->email ? 'mailto:' . $member->email : '#' }}"
       class="pd-tile {{ $member->email ? '' : 'is-disabled' }}"
       @if(!$member->email) aria-disabled="true" tabindex="-1" @endif
       title="{{ $member->email ? 'Email ' . $member->name : 'No email on file' }}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
      <span class="pd-tile-label">Email</span>
    </a>
  </div>

  <div class="pd-actions">
    <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="display:inline">
      @csrf @method('PATCH')
      <input type="hidden" name="op" value="toggle_active">""",
    "view: header tiles")

# ============================================================
# 3) View — Contact block: rename + phone field
# ============================================================
sub(VIEW,
    """      <div class="tm-block-label">Name &amp; email</div>
      <div class="pd-field">
        <div class="pd-field-label">Name</div>
        <div class="pd-field-value"><input class="ia-input" name="name" value="{{ $member->name }}" style="min-width:280px"></div>
      </div>
      <div class="pd-field">
        <div class="pd-field-label">Email</div>
        <div class="pd-field-value"><input class="ia-input" type="email" name="email" value="{{ $member->email }}" style="min-width:320px"></div>
      </div>
      <div style="display:flex;justify-content:flex-end;margin-top:8px">
        <button class="ia-btn ia-btn--primary ia-btn--sm">Save name &amp; email</button>
      </div>""",
    """      <div class="tm-block-label">Contact</div>
      <div class="pd-field">
        <div class="pd-field-label">Name</div>
        <div class="pd-field-value"><input class="ia-input" name="name" value="{{ $member->name }}" style="min-width:280px"></div>
      </div>
      <div class="pd-field">
        <div class="pd-field-label">Email</div>
        <div class="pd-field-value"><input class="ia-input" type="email" name="email" value="{{ $member->email }}" style="min-width:320px"></div>
      </div>
      {{-- MARKER-TEAM-CONTACT --}}
      <div class="pd-field">
        <div class="pd-field-label">Phone</div>
        <div class="pd-field-value">
          <input class="ia-input" type="tel" name="phone" value="{{ $member->phone }}" maxlength="32" style="min-width:200px" placeholder="(509) 555-0142">
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:5px">Used for staff SMS alerts, and for the Call and Text buttons above.</div>
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end;margin-top:8px">
        <button class="ia-btn ia-btn--primary ia-btn--sm">Save contact</button>
      </div>""",
    "view: contact block + phone")

# ============================================================
# 4) View — tile styles
# ============================================================
sub(VIEW,
    """.pd-actions { margin-left:auto; display:flex; gap:6px; }""",
    """.pd-actions { display:flex; gap:6px; }
/* MARKER-TEAM-CONTACT — mirrors .cmd-tile on the customer page. */
.pd-contact-tiles { margin-left:auto; display:grid; grid-template-columns:repeat(3,1fr); gap:6px; min-width:240px; }
.pd-tile { display:flex; flex-direction:column; align-items:center; gap:4px;
           background:var(--ia-surface); border:0.5px solid var(--ia-border);
           border-radius:10px; padding:11px 6px; color:var(--ia-text);
           text-decoration:none; cursor:pointer; }
.pd-tile svg { color:var(--ia-accent); }
.pd-tile:active { transform:scale(0.97); }
.pd-tile:focus-visible { outline:2px solid var(--ia-accent); outline-offset:2px; }
.pd-tile-label { font-size:11px; color:var(--ia-text-dim); font-weight:500; }
.pd-tile.is-disabled { opacity:.32; cursor:not-allowed; pointer-events:none; }
.pd-tile.is-disabled svg { color:var(--ia-text-dim); }
@media (max-width:720px) {
  .pd-head { flex-wrap:wrap; }
  .pd-contact-tiles { margin-left:0; width:100%; order:3; }
}""",
    "view: tile styles")

print("Done. No migration needed (tenant_users.phone already exists). view:clear after deploy.")
