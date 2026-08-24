#!/usr/bin/env python3
"""Dashboard "+ New appointment" should open the create modal.

On the appointments page that button is a <button> calling
openApptModal(). On the dashboard it's a plain link to the appointments
index, so it dumps you on a list and you have to find the button again.

Fix: the dashboard link carries ?new=1, and the appointments index opens
the modal once on arrival. Deliberately NOT including _create_modal on
the dashboard — it carries its own JS, service/customer pickers and
submit handling, and a second copy on another page is a maintenance trap
for a button that's used a few times a day.

The flag is also stripped from the URL after opening, so a refresh or a
back-navigation doesn't reopen the modal unasked.
Run from repo root: python3 apply-dashboard-new-appointment.py
"""
import sys

DASH  = 'resources/views/tenant/dashboard.blade.php'
INDEX = 'resources/views/tenant/appointments/index.blade.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ---------------------------------------------------------------- dashboard
sub(DASH,
    """    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>""",
    """    {{-- MARKER-DASH-NEWAPPT — was a bare link to the list, which meant
         hunting for the real button once you got there. --}}
    <a href="{{ route('tenant.appointments.index', ['new' => 1]) }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>""",
    "dashboard: link carries ?new=1")

# ---------------------------------------------------------------- index
sub(INDEX,
    "@include('tenant.appointments._create_modal')",
    """@include('tenant.appointments._create_modal')

{{-- MARKER-DASH-NEWAPPT — arriving with ?new=1 (from the dashboard, or any
     other "new appointment" entry point) opens the modal straight away. --}}
@if(request()->query('new'))
  <script>
    window.addEventListener('DOMContentLoaded', function () {
      if (typeof window.openApptModal !== 'function') return;
      window.openApptModal();
      // Drop the flag so a refresh or a back-navigation doesn't reopen it.
      try {
        var u = new URL(window.location.href);
        u.searchParams.delete('new');
        window.history.replaceState({}, '', u.pathname + (u.search || '') + u.hash);
      } catch (e) {}
    });
  </script>
@endif""",
    "index: open modal on ?new=1")

print("\\nDone. No migration needed. view:clear after deploy.")
