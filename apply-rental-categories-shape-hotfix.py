#!/usr/bin/env python3
"""Hotfix: content['category_ids'] arrives as an already-decoded array
(the builder's content cast), while the editor writes it as a JSON
string — handle both shapes in the public render, the editor partial,
and the editor's hidden field value.
Run from repo root: python3 apply-rental-categories-shape-hotfix.py
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

PUB = 'resources/views/public/sections/_rental_categories.blade.php'
EDI = 'resources/views/tenant/pages/sections/_rental_categories.blade.php'

sub(PUB,
    """  $rcIds = json_decode($c['category_ids'] ?? '[]', true) ?: [];""",
    """  // MARKER-RENTAL-SECTIONS hotfix — content cast may hand us a decoded
  // array; the editor writes a JSON string. Accept both.
  $rcRaw = $c['category_ids'] ?? [];
  $rcIds = is_array($rcRaw) ? $rcRaw : (json_decode((string) $rcRaw, true) ?: []);""",
    "public: shape-safe decode")

sub(EDI,
    """  $picked = json_decode($get('category_ids', '[]'), true) ?: [];""",
    """  $pickedRaw = $get('category_ids', '[]');
  $picked = is_array($pickedRaw) ? $pickedRaw : (json_decode((string) $pickedRaw, true) ?: []);""",
    "editor: shape-safe decode")

sub(EDI,
    """    <input type="hidden" data-field="category_ids" id="pb2-rcat-json" value="{{ $get('category_ids', '[]') }}">""",
    """    <input type="hidden" data-field="category_ids" id="pb2-rcat-json" value="{{ json_encode(array_values($picked)) }}">""",
    "editor: hidden field encodes cleanly")

print("Done.")
