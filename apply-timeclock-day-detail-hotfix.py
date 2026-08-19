#!/usr/bin/env python3
"""Hotfix for team.blade.php 500: Blade mis-compiles the nested array
inside @json() in the onclick attribute. Precompute the payload in the
@php block and pass the encoded string instead.
Run from repo root: python3 apply-timeclock-day-detail-hotfix.py
"""
import sys

VIEW = 'resources/views/tenant/timeclock/team.blade.php'
s = open(VIEW).read()

old = """          @php $m = $u['days'][$i]; $flag = $u['flags'][$i]; $sess = $u['sessions'][$i]; @endphp
          {{-- MARKER-TIMECLOCK-DAY-DETAIL — clickable cell + session count --}}
          <div class="tt-c day {{ count($sess) ? 'has' : '' }}"
               @if(count($sess))
                 onclick='ttDayOpen(@json(['name' => $u['name'], 'date' => $days[$i]->format('D, M j'), 'total' => $m, 'sessions' => $sess]))'
               @endif>"""

new = """          @php
            $m = $u['days'][$i]; $flag = $u['flags'][$i]; $sess = $u['sessions'][$i];
            // MARKER-TIMECLOCK-DAY-DETAIL — payload precomputed here; inline
            // @json() with a nested array breaks Blade's directive parser.
            $dayPayload = count($sess) ? json_encode([
                'name' => $u['name'],
                'date' => $days[$i]->format('D, M j'),
                'total' => $m,
                'sessions' => $sess,
            ]) : null;
          @endphp
          {{-- MARKER-TIMECLOCK-DAY-DETAIL — clickable cell + session count --}}
          <div class="tt-c day {{ count($sess) ? 'has' : '' }}"
               @if($dayPayload)
                 onclick="ttDayOpen(JSON.parse(this.dataset.day))" data-day="{{ $dayPayload }}"
               @endif>"""

if new in s:
    print("SKIP (already applied)"); sys.exit(0)
if old not in s:
    print("FAIL: anchor not found — paste team.blade.php lines 85-95"); sys.exit(1)
open(VIEW, 'w').write(s.replace(old, new, 1))
print("OK: hotfix applied")
