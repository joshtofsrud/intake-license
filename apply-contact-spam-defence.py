#!/usr/bin/env python3
"""Contact form spam: close the gap the junk is coming through.

The honeypot check has existed server-side since PATCH-399, but only the
FOOTER form ever rendered the hidden field. The main contact section —
the form on the contact page — never had it, so that defence has never
applied to the form most bots find. Everything posted to /contact from
that section sailed past a check that was already written.

Two other holes closed at the same time:

  * /contact had NO rate limit, while gift-card purchase, balance check,
    account register and login all carry throttle:. One script could
    post without limit.
  * No minimum fill time. A signed timestamp is now planted in the form
    and submissions completed in under 3 seconds are dropped — no human
    fills in a name, email and message that fast.

Deliberately NOT added: link-count or keyword filtering as a hard drop.
Silently discarding a real customer's message is a worse failure than
junk mail. If that's wanted later it should FLAG a thread, not delete it.
Run from repo root: python3 apply-contact-spam-defence.py
"""
import sys

FORM   = 'resources/views/public/sections/_contact_form.blade.php'
FOOTER = 'resources/views/public/sections/_footer.blade.php'
CTRL   = 'app/Http/Controllers/Tenant/PublicController.php'
ROUTES = 'routes/web.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

HONEY = """      {{-- MARKER-CONTACT-SPAM — the honeypot the controller has always
           checked for. This form never rendered it, so PATCH-399's check
           did nothing here; the footer form had it all along. --}}
      <input type="text" name="company_website" tabindex="-1" autocomplete="off" aria-hidden="true"
             style="position:absolute !important;left:-9999px !important;top:auto;width:1px;height:1px;opacity:0;pointer-events:none">
      {{-- Signed render time: a submission that arrives in under 3 seconds
           wasn't typed by a person. --}}
      <input type="hidden" name="form_started_at" value="{{ encrypt(time()) }}">
"""

# ---------------------------------------------------------------- forms
sub(FORM,
    """    <form method="POST" action="/contact">
      @csrf
""",
    """    <form method="POST" action="/contact">
      @csrf
""" + HONEY,
    "contact form: honeypot + timestamp")

sub(FOOTER,
    """                <input type="text" name="company_website" tabindex="-1" autocomplete="off" aria-hidden="true"
                       style="position:absolute !important;left:-9999px !important;top:auto;width:1px;height:1px;opacity:0;pointer-events:none">""",
    """                <input type="text" name="company_website" tabindex="-1" autocomplete="off" aria-hidden="true"
                       style="position:absolute !important;left:-9999px !important;top:auto;width:1px;height:1px;opacity:0;pointer-events:none">
                {{-- MARKER-CONTACT-SPAM — same timing check as the contact section. --}}
                <input type="hidden" name="form_started_at" value="{{ encrypt(time()) }}">""",
    "footer form: timestamp")

# ---------------------------------------------------------------- controller
sub(CTRL,
    """        if (filled($request->input('company_website'))) {
            return back()->with('contact_success', true);
        }""",
    """        if (filled($request->input('company_website'))) {
            return back()->with('contact_success', true);
        }

        // MARKER-CONTACT-SPAM — minimum fill time. The value is encrypted at
        // render, so it can't be forged or replayed with a stale timestamp.
        // Same silent success as the honeypot: a bot that learns which check
        // caught it just works around that one.
        $startedRaw = $request->input('form_started_at');
        if (filled($startedRaw)) {
            try {
                $started = (int) decrypt($startedRaw);
                if (time() - $started < 3) {
                    return back()->with('contact_success', true);
                }
                // Older than a day means a stale page or a replayed payload.
                if (time() - $started > 86400) {
                    return back()->with('contact_success', true);
                }
            } catch (\\Throwable $e) {
                // Tampered or undecryptable — treat as a bot, quietly.
                return back()->with('contact_success', true);
            }
        }""",
    "controller: timing check")

# ---------------------------------------------------------------- route
sub(ROUTES,
    """    Route::post('/contact',  [TenantControllers\\PublicController::class, 'contact'])->name('tenant.contact.submit');""",
    """    // MARKER-CONTACT-SPAM — this was the only public POST without a limit;
    // gift cards, register and login have all had one.
    Route::post('/contact',  [TenantControllers\\PublicController::class, 'contact'])->name('tenant.contact.submit')->middleware('throttle:5,1');""",
    "route: rate limit")

print("\\nDone. No migration needed. view:clear after deploy.")
