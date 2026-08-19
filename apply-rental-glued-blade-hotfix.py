#!/usr/bin/env python3
"""Hotfix for the spotlight 500. Two defects:
1. Glued Blade directives — `fleet@if(...)` / `available@if(...)` don't
   compile (word char glued to @if) but their @endif does → orphan endif
   → syntax error. Space added.
2. The photos patch's blind replace corrupted the $spImage fallback into
   a self-reference; restored to section-image-wins, model-photo-fallback.
Run from repo root: python3 apply-rental-glued-blade-hotfix.py
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

sub('resources/views/public/sections/_rental_spotlight.blade.php',
    "{{ $spModel->sp_unit_count }} in the fleet@if($spSizes->isNotEmpty()) · sizes {{ $spSizes->implode(', ') }}@endif",
    "{{ $spModel->sp_unit_count }} in the fleet @if($spSizes->isNotEmpty()) · sizes {{ $spSizes->implode(', ') }} @endif",
    "spotlight: unglue @if")

sub('resources/views/public/sections/_rental_spotlight.blade.php',
    "$spImage = !empty($spImage) ? $c['image_url'] : ($spModel->image_url ?? '');",
    "$spImage = !empty($c['image_url']) ? $c['image_url'] : ($spModel->image_url ?? '');",
    "spotlight: restore fallback logic")

sub('resources/views/public/sections/_rental_browse.blade.php',
    "{{ $g['count'] }} available@if($g['sizes']) · {{ implode(', ', $g['sizes']) }}@endif",
    "{{ $g['count'] }} available @if($g['sizes']) · {{ implode(', ', $g['sizes']) }} @endif",
    "browse: unglue @if")

print("Done.")
