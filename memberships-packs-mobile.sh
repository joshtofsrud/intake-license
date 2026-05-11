#!/bin/bash
# ============================================================================
# memberships-packs-mobile.sh   (patch #37)
# ----------------------------------------------------------------------------
# Apply the same parallel desktop+mobile render pattern from #34 (templates)
# to the two remaining class CRUD pages:
#
#   /admin/classes/memberships
#   /admin/classes/packs
#
# Both currently use a 5-col / 6-col desktop grid that's unreadable on phone.
# This patch adds a mobile card list rendered below the desktop table, swapped
# via @media(max-width:640px). Desktop UX unchanged.
#
# Cards include:
#   - Name + inline chip (Type/Credits) + inline Inactive badge when applicable
#   - Description, 2-line clamp
#   - Edit icon button top-right (matches templates page exactly)
#   - Meta row with labeled values (Price, Per-class, Expires, Sold, etc.)
#
# Per-class price on packs gets lime accent — it's the implicit savings
# signal that customers buy on.
#
# No controller changes. No new routes. View-layer only.
#
# Files touched:
#   resources/views/tenant/classes/membership-products.blade.php
#   resources/views/tenant/classes/pack-products.blade.php
#
# Deploy (CSS/Blade only):
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 37: memberships + packs mobile parallel render"

# ----------------------------------------------------------------------------
# 1. Memberships page
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/classes/membership-products.blade.php")
s = p.read_text()

if "cl-mp-mobile" in s:
    print("    SKIP memberships mobile render (already present)")
else:
    # 1a. Append CSS for the mobile card list.
    css = """
/* Memberships mobile card list — parallel render (patch #37) */
.cl-mp-mobile{display:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-mp-row-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;flex-direction:column;gap:8px}
.cl-mp-row-m:last-child{border-bottom:none}
.cl-mp-row-m.is-inactive{opacity:.55}
.cl-mp-top-m{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.cl-mp-identity-m{min-width:0;flex:1}
.cl-mp-name-m{font-size:15px;font-weight:500;color:var(--ia-text);line-height:1.25;display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.cl-mp-name-text-m{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-mp-chip-m{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500;flex-shrink:0}
.cl-mp-chip-m.unlimited{background:var(--ia-accent-soft);color:var(--ia-accent)}
.cl-mp-chip-m.capped{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-mp-chip-m.inactive{background:var(--ia-surface-2);color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em;font-size:10px}
.cl-mp-desc-m{font-size:12px;color:var(--ia-text-muted);margin-top:3px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cl-mp-actions-m{display:flex;gap:4px;flex-shrink:0}
.cl-mp-icon-btn-m{width:32px;height:32px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);cursor:pointer;transition:all var(--ia-t);font-family:inherit}
.cl-mp-icon-btn-m:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-mp-meta-row-m{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;align-items:center}
.cl-mp-meta-item-m{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.cl-mp-meta-label-m{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim,rgba(255,255,255,.38));font-weight:500}
.cl-mp-meta-value-m{color:var(--ia-text);font-weight:500}
@media(max-width:640px){
  .cl-card > .cl-table-head,
  .cl-card > .cl-table-row{display:none}
  .cl-mp-mobile{display:block}
}
"""
    style_close = "</style>\n@endpush"
    if s.count(style_close) != 1:
        raise SystemExit(f"ABORT: memberships </style>@endpush count = {s.count(style_close)}, expected 1")
    s = s.replace(style_close, css + style_close)

    # 1b. Insert the mobile card list after the desktop @forelse, before
    #     the closing </div> of .cl-card and the {{-- Add modal --}} comment.
    desk_anchor = """  @endforelse
</div>

{{-- Add modal --}}"""
    mobile_block = """  @endforelse

  {{-- Mobile card list (parallel render, ≤640px). Desktop table above hides
       on mobile via the CSS swap; same data, card-shaped. --}}
  <div class="cl-mp-mobile">
    @forelse($products as $p)
      <div class="cl-mp-row-m {{ $p->is_active ? '' : 'is-inactive' }}">
        <div class="cl-mp-top-m">
          <div class="cl-mp-identity-m">
            <div class="cl-mp-name-m">
              <span class="cl-mp-name-text-m">{{ $p->name }}</span>
              @if($p->isUnlimited())
                <span class="cl-mp-chip-m unlimited">Unlimited</span>
              @else
                <span class="cl-mp-chip-m capped">Capped · {{ $p->monthly_limit }}/mo</span>
              @endif
              @if(!$p->is_active)
                <span class="cl-mp-chip-m inactive">Inactive</span>
              @endif
            </div>
            @if($p->description)
              <div class="cl-mp-desc-m">{{ $p->description }}</div>
            @endif
          </div>
          <div class="cl-mp-actions-m">
            <button type="button" class="cl-mp-icon-btn-m" title="Edit" onclick="openEditModal({{ $p->toJson() }})">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
        <div class="cl-mp-meta-row-m">
          <span class="cl-mp-meta-item-m"><span class="cl-mp-meta-label-m">Price/mo</span> <span class="cl-mp-meta-value-m">${{ number_format($p->price_cents / 100, 2) }}</span></span>
          <span class="cl-mp-meta-item-m" style="margin-left:auto"><span class="cl-mp-meta-label-m">Subs</span> <span class="cl-mp-meta-value-m">{{ $p->memberships_count }}</span></span>
        </div>
      </div>
    @empty
      {{-- Desktop empty state renders above; nothing extra needed here. --}}
    @endforelse
  </div>
</div>

{{-- Add modal --}}"""
    if s.count(desk_anchor) != 1:
        raise SystemExit(f"ABORT: memberships desktop-table close anchor count = {s.count(desk_anchor)}, expected 1")
    s = s.replace(desk_anchor, mobile_block)

    p.write_text(s)
    print("    UPDATED membership-products.blade.php — mobile card list appended")
PYEOF

# ----------------------------------------------------------------------------
# 2. Packs page
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/classes/pack-products.blade.php")
s = p.read_text()

if "cl-pk-mobile" in s:
    print("    SKIP packs mobile render (already present)")
else:
    # 2a. Append CSS for the mobile card list. Same structure as memberships,
    #     just with .cl-pk- prefix to avoid any cross-page bleed if a future
    #     change introduces page-scoped overrides.
    css = """
/* Packs mobile card list — parallel render (patch #37) */
.cl-pk-mobile{display:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-pk-row-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;flex-direction:column;gap:8px}
.cl-pk-row-m:last-child{border-bottom:none}
.cl-pk-row-m.is-inactive{opacity:.55}
.cl-pk-top-m{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.cl-pk-identity-m{min-width:0;flex:1}
.cl-pk-name-m{font-size:15px;font-weight:500;color:var(--ia-text);line-height:1.25;display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.cl-pk-name-text-m{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-pk-chip-m{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500;flex-shrink:0}
.cl-pk-chip-m.credits{background:rgba(117,168,224,.15);color:#75A8E0}
.cl-pk-chip-m.inactive{background:var(--ia-surface-2);color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em;font-size:10px}
.cl-pk-desc-m{font-size:12px;color:var(--ia-text-muted);margin-top:3px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cl-pk-actions-m{display:flex;gap:4px;flex-shrink:0}
.cl-pk-icon-btn-m{width:32px;height:32px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);cursor:pointer;transition:all var(--ia-t);font-family:inherit}
.cl-pk-icon-btn-m:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-pk-meta-row-m{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;align-items:center}
.cl-pk-meta-item-m{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.cl-pk-meta-label-m{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim,rgba(255,255,255,.38));font-weight:500}
.cl-pk-meta-value-m{color:var(--ia-text);font-weight:500}
.cl-pk-meta-value-m.accent{color:var(--ia-accent)}
@media(max-width:640px){
  .cl-card > .cl-table-head,
  .cl-card > .cl-table-row{display:none}
  .cl-pk-mobile{display:block}
}
"""
    style_close = "</style>\n@endpush"
    if s.count(style_close) != 1:
        raise SystemExit(f"ABORT: packs </style>@endpush count = {s.count(style_close)}, expected 1")
    s = s.replace(style_close, css + style_close)

    # 2b. Insert the mobile card list. The per-class calculation reuses the
    #     same expression the desktop view already uses for parity.
    desk_anchor = """  @endforelse
</div>

{{-- Add modal --}}"""
    mobile_block = """  @endforelse

  {{-- Mobile card list (parallel render, ≤640px). Per-class price gets the
       lime accent — it's the implicit-savings signal customers buy on. --}}
  <div class="cl-pk-mobile">
    @forelse($products as $p)
      <div class="cl-pk-row-m {{ $p->is_active ? '' : 'is-inactive' }}">
        <div class="cl-pk-top-m">
          <div class="cl-pk-identity-m">
            <div class="cl-pk-name-m">
              <span class="cl-pk-name-text-m">{{ $p->name }}</span>
              <span class="cl-pk-chip-m credits">{{ $p->credit_count }} credits</span>
              @if(!$p->is_active)
                <span class="cl-pk-chip-m inactive">Inactive</span>
              @endif
            </div>
            @if($p->description)
              <div class="cl-pk-desc-m">{{ $p->description }}</div>
            @endif
          </div>
          <div class="cl-pk-actions-m">
            <button type="button" class="cl-pk-icon-btn-m" title="Edit" onclick="openEditModal({{ $p->toJson() }})">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 2.5l2 2L4 12H2v-2L9.5 2.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
        <div class="cl-pk-meta-row-m">
          <span class="cl-pk-meta-item-m"><span class="cl-pk-meta-label-m">Price</span> <span class="cl-pk-meta-value-m">${{ number_format($p->price_cents / 100, 2) }}</span></span>
          <span class="cl-pk-meta-item-m"><span class="cl-pk-meta-label-m">Per class</span> <span class="cl-pk-meta-value-m accent">${{ number_format($p->price_cents / $p->credit_count / 100, 2) }}</span></span>
          <span class="cl-pk-meta-item-m"><span class="cl-pk-meta-label-m">Expires</span> <span class="cl-pk-meta-value-m">{{ $p->expiry_days }}d</span></span>
          <span class="cl-pk-meta-item-m" style="margin-left:auto"><span class="cl-pk-meta-label-m">Sold</span> <span class="cl-pk-meta-value-m">{{ $p->customer_packs_count }}</span></span>
        </div>
      </div>
    @empty
      {{-- Desktop empty state renders above; nothing extra needed here. --}}
    @endforelse
  </div>
</div>

{{-- Add modal --}}"""
    if s.count(desk_anchor) != 1:
        raise SystemExit(f"ABORT: packs desktop-table close anchor count = {s.count(desk_anchor)}, expected 1")
    s = s.replace(desk_anchor, mobile_block)

    p.write_text(s)
    print("    UPDATED pack-products.blade.php — mobile card list appended")
PYEOF

cat <<EONOTE

==> Patch 37 applied locally.

To deploy:
  git add -A
  git commit -m "feat(mobile): memberships + packs card-list parallel render (#37)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

  (No migration, no composer install — pure view/CSS.)

What this adds:
  - Memberships: mobile card list with Unlimited/Capped chip + Inactive badge
  - Packs: mobile card list with credits chip + Per-class lime accent
  - Both: tap card → opens existing edit modal (no behavior change)
  - Desktop: unchanged

Out of scope (defer to v1.1):
  - Delete buttons on memberships/packs (controller methods don't exist yet,
    and safe-deletion guards against orphaned credits/subscriptions need
    real thought)
  - Active/inactive toggle directly from the card (currently via edit modal)
EONOTE
