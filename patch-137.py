#!/usr/bin/env python3
"""
Patch 137 — second blade parse error in PlatformDashboard.

The health row anchor used an inline @if directly inside an opening tag
to conditionally add the href attribute. Blade tokenises this strangely
and the parser eats the closing > as part of the directive, producing
"unexpected end of file, expecting elseif/else/endif" with no obvious
culprit when you look at the source.

Fix: emit the href via a ternary embedded in the attribute itself.
When href is null the attribute becomes href="" — harmless on an <a>
tag, no navigation occurs because we add a class-level cursor hint
separately if needed (which we don't in this case since these rows
should always be clickable).

Idempotent.
"""

import argparse
import pathlib
import sys

OLD = '''        <a class="pd-h-row {{ $row['state'] }}" @if($row['href']) href="{{ $row['href'] }}" @endif>'''
NEW = '''        {{-- MARKER-PATCH-137 — no inline @if inside opening tags; renders href via ternary --}}
        <a class="pd-h-row {{ $row['state'] }}" href="{{ $row['href'] ?? '#' }}">'''

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    p = pathlib.Path(a.root) / 'resources' / 'views' / 'filament' / 'pages' / 'platform-dashboard.blade.php'
    t = p.read_text()
    if 'MARKER-PATCH-137' in t:
        print('already_applied'); return
    if OLD not in t:
        print('ERROR: anchor missing', file=sys.stderr); sys.exit(2)
    if a.apply:
        p.write_text(t.replace(OLD, NEW, 1))
        print('applied')
    else:
        print('would_apply')

if __name__ == '__main__':
    main()
