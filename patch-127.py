#!/usr/bin/env python3
"""
Patch 127 — Clear setup instructions on the domain detail page.

Replaces the existing DNS records block in
resources/views/tenant/settings/domains/show.blade.php with a 3-step
instruction layout that names every record the tenant will need to
add — including the Cloudflare-emitted _acme-challenge CNAME, which
the previous UI never mentioned at all.

  Step 1 — Ownership TXT      (we generate the value)
  Step 2 — Routing CNAME      (we know the target)
  Step 3 — ACME challenge     (Cloudflare emails the value; informational
                               only — example shape shown, not copyable)

Out of scope (deliberate):
  - No verify/check-live functionality. Pure instructions.
  - No cleanup of unused patch-125 columns or view block. Left in place
    to keep this patch small and focused.

Usage:
    python3 patch-127.py /path/to/intake-license             # dry-run
    python3 patch-127.py /path/to/intake-license --apply     # write

Idempotent.
"""

import argparse
import pathlib
import sys


# ─────────────────────────────────────────────────────────────────────
# The DNS records block from patch-120 / DNS-HOTFIX. Replaced wholesale.
# ─────────────────────────────────────────────────────────────────────

OLD_BLOCK = """{{-- ───────────── DNS RECORDS (MARKER-DNS-HOTFIX — always visible, state-aware framing) ───────────── --}}
@if($statusKey !== 'suspended')
  @php
    // State-aware presentation:
    //   - prominent      → tenant needs to act (pending_dns, error)
    //   - reference      → setup in progress; tenant can sanity-check (verifying, issuing_cert)
    //   - collapsed      → already working; keep visible for reference but de-emphasized (active)
    $dnsPresentation = match($statusKey) {
      'pending_dns', 'error' => 'prominent',
      'verifying', 'issuing_cert' => 'reference',
      'active' => 'collapsed',
      default => 'reference',
    };
  @endphp

  @if($dnsPresentation === 'collapsed')
    <details class="ia-card" style="margin-bottom:16px">
      <summary style="cursor:pointer;font-size:13px;color:var(--ia-text-3,#888);font-weight:600">
        DNS records on file <span style="font-weight:400;opacity:.7">— keep these in place to stay live</span>
      </summary>
      <div style="padding:14px 0 0">
  @else
    <div class="ia-card" style="margin-bottom:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">
          @if($dnsPresentation === 'prominent')
            Add these records at your registrar
          @else
            DNS records (for reference)
          @endif
        </span>
      </div>
      @if($dnsPresentation === 'prominent')
        <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:12px;line-height:1.55">
          Wherever you bought <code style="font-family:var(--ia-font-mono,monospace)">{{ $domain->hostname }}</code> — GoDaddy, Cloudflare, Namecheap, etc.
        </p>
      @else
        <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:12px;line-height:1.55">
          We detected the records below on <code style="font-family:var(--ia-font-mono,monospace)">{{ $domain->hostname }}</code>. Keep them in place — if any are removed, your domain will stop serving customers.
        </p>
      @endif
  @endif

      <div class="ds-dns">
        <div class="ds-dns-row head">
          <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
        </div>
        <div class="ds-dns-row">
          <div><span class="dm-pill verifying" style="padding:2px 8px">TXT</span></div>
          <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordName() }}</div>
          <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordValue() }}</div>
          <button type="button" class="ds-copy-btn" data-copy="{{ $domain->verificationRecordValue() }}">Copy</button>
        </div>
        <div class="ds-dns-row">
          <div><span class="dm-pill verifying" style="padding:2px 8px">CNAME</span></div>
          <div class="ds-dns-mono ds-dns-value">{{ $domain->hostname }}</div>
          <div class="ds-dns-mono ds-dns-value">{{ $cnameTarget }}</div>
          <button type="button" class="ds-copy-btn" data-copy="{{ $cnameTarget }}">Copy</button>
        </div>
      </div>

      @if($dnsPresentation === 'prominent')
        <p style="font-size:12px;color:var(--ia-text-3,#888);margin-top:14px;line-height:1.55">
          <strong style="color:#F59E0B">Apex domain note:</strong> Some registrars don't allow CNAME on the root domain.
          If yours doesn't, use a CNAME flattening feature (Cloudflare's default, or "ANAME" / "ALIAS" records elsewhere),
          or use a subdomain like <code style="font-family:var(--ia-font-mono,monospace)">www.{{ $domain->hostname }}</code>.
        </p>
      @endif

  @if($dnsPresentation === 'collapsed')
      </div>
    </details>
  @else
    </div>
  @endif
@endif"""


# ─────────────────────────────────────────────────────────────────────
# The new 3-step instructions block (MARKER-PATCH-127).
# Visible in all non-suspended states. When active, shown collapsed via
# <details> as a reference; otherwise prominent with full numbered steps.
# ─────────────────────────────────────────────────────────────────────

NEW_BLOCK = """{{-- ───────────── DNS RECORDS — 3-STEP SETUP (MARKER-PATCH-127) ───────────── --}}
@if($statusKey !== 'suspended')
  @php
    $instructionsPresentation = $statusKey === 'active' ? 'collapsed' : 'prominent';
  @endphp

  @push('styles')
  <style>
    .ds-steps { display:flex; flex-direction:column; gap:22px; }
    .ds-step-block { display:grid; grid-template-columns:30px 1fr; gap:14px; align-items:flex-start; }
    .ds-step-num { width:24px; height:24px; border-radius:50%; background:rgba(255,255,255,.04); border:1px solid var(--ia-border2,rgba(255,255,255,.14)); color:var(--ia-muted,rgba(255,255,255,.5)); display:flex; align-items:center; justify-content:center; font-family:var(--ia-font-mono,monospace); font-size:11px; font-weight:500; margin-top:1px; }
    .ds-step-title { font-size:14px; font-weight:500; margin:0 0 4px; color:var(--ia-text); }
    .ds-step-desc  { font-size:12.5px; color:var(--ia-muted,rgba(255,255,255,.55)); line-height:1.6; }
    .ds-step-desc code { font-family:var(--ia-font-mono,monospace); font-size:11.5px; background:rgba(255,255,255,.06); padding:1px 5px; border-radius:3px; color:var(--ia-text); }
    .ds-rec-note { display:grid; grid-template-columns:80px 1fr 1fr 36px; gap:10px; padding:11px 14px; background:rgba(245,158,11,.06); border-bottom:1px solid var(--ia-border); }
    .ds-rec-note:last-child { border-bottom:none; }
    .ds-rec-note-pill { font-family:var(--ia-font-mono,monospace); font-size:10.5px; padding:2px 8px; border-radius:4px; background:rgba(245,158,11,.14); color:#F59E0B; display:inline-block; height:fit-content; }
    .ds-rec-note-name, .ds-rec-note-value { font-family:var(--ia-font-mono,monospace); font-size:11.5px; color:var(--ia-muted,rgba(255,255,255,.55)); word-break:break-all; line-height:1.55; }
    .ds-rec-note-value em { font-style:normal; color:rgba(245,158,11,.85); }
  </style>
  @endpush

  @if($instructionsPresentation === 'collapsed')
    <details class="ia-card" style="margin-bottom:16px">
      <summary style="cursor:pointer;font-size:13px;color:var(--ia-text-3,#888);font-weight:600">
        Setup records <span style="font-weight:400;opacity:.7">— keep these in place to stay live</span>
      </summary>
      <div style="padding:18px 0 0">
  @else
    <div class="ia-card" style="margin-bottom:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">Add three DNS records at your registrar</span>
      </div>
      <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:18px;line-height:1.55">
        Wherever you bought <code style="font-family:var(--ia-font-mono,monospace)">{{ $domain->hostname }}</code> — GoDaddy, Cloudflare, Namecheap, etc. Work through the steps in order; the last record comes from Cloudflare after the first two are live.
      </p>
  @endif

      <div class="ds-steps">

        {{-- Step 1 — Ownership TXT --}}
        <div class="ds-step-block">
          <div class="ds-step-num">1</div>
          <div style="min-width:0">
            <div class="ds-step-title">Prove you own the domain</div>
            <div class="ds-step-desc">
              Add this TXT record so we can confirm you control <code>{{ $domain->hostname }}</code>.
            </div>
            <div class="ds-dns" style="margin-top:10px">
              <div class="ds-dns-row head">
                <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
              </div>
              <div class="ds-dns-row">
                <div><span class="dm-pill verifying" style="padding:2px 8px">TXT</span></div>
                <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordName() }}</div>
                <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordValue() }}</div>
                <button type="button" class="ds-copy-btn" data-copy="{{ $domain->verificationRecordValue() }}">Copy</button>
              </div>
            </div>
          </div>
        </div>

        {{-- Step 2 — Routing CNAME --}}
        <div class="ds-step-block">
          <div class="ds-step-num">2</div>
          <div style="min-width:0">
            <div class="ds-step-title">Send your traffic to us</div>
            <div class="ds-step-desc">
              Add this CNAME so visitors to <code>{{ $domain->hostname }}</code> reach your shop.
            </div>
            <div class="ds-dns" style="margin-top:10px">
              <div class="ds-dns-row head">
                <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
              </div>
              <div class="ds-dns-row">
                <div><span class="dm-pill verifying" style="padding:2px 8px">CNAME</span></div>
                <div class="ds-dns-mono ds-dns-value">{{ $domain->hostname }}</div>
                <div class="ds-dns-mono ds-dns-value">{{ $cnameTarget }}</div>
                <button type="button" class="ds-copy-btn" data-copy="{{ $cnameTarget }}">Copy</button>
              </div>
            </div>
            <p style="font-size:11.5px;color:var(--ia-text-3,#888);margin-top:10px;line-height:1.55">
              <strong style="color:#F59E0B">Apex domain note:</strong> Some registrars don't permit a CNAME on the root domain. If yours doesn't, use a CNAME-flattening feature (Cloudflare's is automatic; some registrars call it ANAME or ALIAS), or use a subdomain like <code style="font-family:var(--ia-font-mono,monospace)">www.{{ $domain->hostname }}</code>.
            </p>
          </div>
        </div>

        {{-- Step 3 — ACME challenge (informational, value comes from Cloudflare) --}}
        <div class="ds-step-block">
          <div class="ds-step-num">3</div>
          <div style="min-width:0">
            <div class="ds-step-title">Authorise the HTTPS certificate</div>
            <div class="ds-step-desc">
              Once steps 1 and 2 are live, our certificate provider (Cloudflare) will email you with a third record to add. It looks like the example below — the exact value will be unique to your domain. <strong style="color:var(--ia-text);font-weight:500">Use the value from the email, not this example.</strong>
            </div>
            <div class="ds-dns" style="margin-top:10px">
              <div class="ds-dns-row head">
                <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
              </div>
              <div class="ds-rec-note">
                <div><span class="ds-rec-note-pill">CNAME</span></div>
                <div class="ds-rec-note-name">_acme-challenge.{{ $domain->hostname }}</div>
                <div class="ds-rec-note-value"><em>(Cloudflare-issued, ends in <code style="font-family:inherit">.dcv.cloudflare.com</code>)</em></div>
                <div></div>
              </div>
            </div>
          </div>
        </div>

      </div>

  @if($instructionsPresentation === 'collapsed')
      </div>
    </details>
  @else
    </div>
  @endif
@endif"""


def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {}
    p = root / 'resources' / 'views' / 'tenant' / 'settings' / 'domains' / 'show.blade.php'
    text = p.read_text()

    if 'MARKER-PATCH-127' in text:
        summary['already_applied'] = 1
        return summary

    if OLD_BLOCK not in text:
        print("ERROR: anchor block not found in show.blade.php.", file=sys.stderr)
        print("Either the file changed since patch 125 or the script's OLD_BLOCK is out of sync.", file=sys.stderr)
        sys.exit(2)

    new_text = text.replace(OLD_BLOCK, NEW_BLOCK, 1)
    if apply:
        p.write_text(new_text)
    summary['view_swap'] = 1
    return summary


def verify(root: pathlib.Path) -> list[str]:
    failures = []
    text = (root / 'resources' / 'views' / 'tenant' / 'settings' / 'domains' / 'show.blade.php').read_text()
    if 'MARKER-PATCH-127' not in text:
        failures.append("MARKER-PATCH-127 not in show.blade.php")
    if 'Authorise the HTTPS certificate' not in text:
        failures.append("Step 3 heading missing")
    if '_acme-challenge.{{ $domain->hostname }}' not in text:
        failures.append("ACME challenge record name not rendered")
    if 'dcv.cloudflare.com' not in text:
        failures.append("Cloudflare DCV hint not present")
    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root', help='Path to intake-license repo root')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'routes' / 'web.php').exists():
        print(f"ERROR: {root} does not look like an intake repo", file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f"=== patch-127 [{mode}] target={root} ===\n")

    summary = process(root, apply=args.apply)
    print("Summary:")
    for k, v in summary.items():
        print(f"  {k}: {v}")

    if args.apply:
        print("\nVerifying...")
        failures = verify(root)
        if failures:
            print("\nFAIL:")
            for f in failures:
                print(f"  - {f}")
            sys.exit(1)
        print("  all checks pass")
    else:
        print("\n(dry-run — no files written. Re-run with --apply to commit.)")


if __name__ == '__main__':
    main()
