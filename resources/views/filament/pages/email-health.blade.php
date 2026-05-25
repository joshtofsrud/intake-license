{{-- MARKER-PATCH-148 — master-admin email health --}}
<x-filament-panels::page>

<style>
  .eh {
    --eh-bg: #ffffff;
    --eh-surface-2: #f7f7f8;
    --eh-border: rgba(0,0,0,.08);
    --eh-border-2: rgba(0,0,0,.15);
    --eh-text: #111827;
    --eh-text-muted: rgba(17,24,39,.7);
    --eh-text-dim: rgba(17,24,39,.5);
    --eh-ok: #16A34A;
    --eh-warn: #D97706;
    --eh-bad: #DC2626;
    --eh-info: #0284C7;
    --eh-mono: 'JetBrains Mono', ui-monospace, monospace;
    color: var(--eh-text);
    font-size: 13.5px;
  }
  .dark .eh {
    --eh-bg: #131313;
    --eh-surface-2: #1a1a1a;
    --eh-border: rgba(255,255,255,.08);
    --eh-border-2: rgba(255,255,255,.18);
    --eh-text: #f0f0f0;
    --eh-text-muted: rgba(255,255,255,.62);
    --eh-text-dim: rgba(255,255,255,.42);
  }

  .eh-tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
  .eh-tile { background: var(--eh-bg); border: 1px solid var(--eh-border); border-radius: 10px; padding: 16px 18px; }
  .eh-tile-lbl { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--eh-text-dim); font-weight: 500; }
  .eh-tile-val { font-size: 28px; font-weight: 600; letter-spacing: -0.01em; line-height: 1.15; margin-top: 4px; }
  .eh-tile-sub { font-size: 11.5px; color: var(--eh-text-dim); margin-top: 4px; font-family: var(--eh-mono); }

  .eh-section { margin-bottom: 28px; }
  .eh-section-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
  .eh-section-title { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: var(--eh-text-muted); font-weight: 500; }
  .eh-section-sub { font-size: 11.5px; color: var(--eh-text-dim); }

  .eh-card { background: var(--eh-bg); border: 1px solid var(--eh-border); border-radius: 10px; overflow: hidden; }

  .eh-row { display: grid; gap: 14px; padding: 12px 18px; border-bottom: 1px solid var(--eh-border); align-items: center; }
  .eh-row:last-child { border-bottom: none; }
  .eh-row:hover { background: var(--eh-surface-2); }
  .eh-row-head { background: var(--eh-surface-2); font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--eh-text-muted); padding: 9px 18px; }
  .eh-row-head:hover { background: var(--eh-surface-2); }

  .eh-pill { display: inline-block; padding: 2px 9px; font-size: 11px; border-radius: 999px; }
  .eh-pill.ok { background: rgba(22,163,74,.12); color: var(--eh-ok); }
  .eh-pill.warn { background: rgba(217,119,6,.14); color: var(--eh-warn); }
  .eh-pill.bad { background: rgba(220,38,38,.12); color: var(--eh-bad); }
  .eh-pill.info { background: rgba(2,132,199,.12); color: var(--eh-info); }
  .eh-pill.muted { background: var(--eh-surface-2); color: var(--eh-text-muted); }

  .eh-empty { padding: 32px 18px; text-align: center; color: var(--eh-text-muted); font-size: 13px; }

  .eh-search { display: flex; gap: 8px; margin-bottom: 14px; }
  .eh-input { flex: 1; padding: 7px 11px; border: 1px solid var(--eh-border-2); border-radius: 6px; background: var(--eh-bg); color: var(--eh-text); font-size: 13px; font-family: inherit; }

  .eh-mono { font-family: var(--eh-mono); }
</style>

<div class="eh">

  {{-- Top tiles --}}
  <div class="eh-tiles">
    <div class="eh-tile">
      <div class="eh-tile-lbl">Platform suppressions</div>
      <div class="eh-tile-val">{{ number_format($tiles['platform']) }}</div>
      <div class="eh-tile-sub">addresses blocked everywhere</div>
    </div>
    <div class="eh-tile">
      <div class="eh-tile-lbl">Tenant suppressions</div>
      <div class="eh-tile-val">{{ number_format($tiles['tenant']) }}</div>
      <div class="eh-tile-sub">blocked at one tenant</div>
    </div>
    <div class="eh-tile">
      <div class="eh-tile-lbl">Bounces · 7d</div>
      <div class="eh-tile-val">{{ number_format($tiles['bounces7']) }}</div>
      <div class="eh-tile-sub">across all tenants</div>
    </div>
    <div class="eh-tile">
      <div class="eh-tile-lbl">Complaints · 7d</div>
      <div class="eh-tile-val">{{ number_format($tiles['complaints7']) }}</div>
      <div class="eh-tile-sub">SES reputation risk</div>
    </div>
  </div>

  {{-- Search --}}
  <div class="eh-section">
    <div class="eh-section-head">
      <div class="eh-section-title">Find an address</div>
    </div>
    <form method="GET" class="eh-search">
      <input class="eh-input" type="text" name="q" placeholder="Search suppressed addresses across all tenants…" value="{{ $searchTerm }}">
      <button type="submit" style="padding: 7px 14px; border-radius: 6px; border: 1px solid var(--eh-border-2); background: var(--eh-bg); color: var(--eh-text); font-size: 13px; cursor: pointer;">Search</button>
    </form>

    @if($searchHits !== null)
      <div class="eh-card">
        @if(count($searchHits) === 0)
          <div class="eh-empty">No matches for "{{ $searchTerm }}"</div>
        @else
          <div class="eh-row eh-row-head" style="grid-template-columns: 1.6fr 130px 130px 110px;">
            <div>Email</div><div>Scope</div><div>Reason</div><div>When</div>
          </div>
          @foreach($searchHits as $hit)
            <div class="eh-row" style="grid-template-columns: 1.6fr 130px 130px 110px;">
              <div class="eh-mono" style="font-size: 12.5px;">{{ $hit['email'] }}</div>
              <div>
                @if($hit['is_platform'])
                  <span class="eh-pill info">Platform-wide</span>
                @else
                  <span class="eh-pill muted">{{ $hit['tenant_name'] ?? '—' }}</span>
                @endif
              </div>
              <div>
                @if($hit['reason'] === 'bounce')<span class="eh-pill bad">Bounce</span>
                @elseif($hit['reason'] === 'complaint')<span class="eh-pill warn">Complaint</span>
                @else<span class="eh-pill muted">{{ ucfirst($hit['reason']) }}</span>
                @endif
              </div>
              <div style="font-size: 11.5px; color: var(--eh-text-dim); font-family: var(--eh-mono);">
                {{ $hit['suppressed_at']?->diffForHumans(null, true) }} ago
              </div>
            </div>
          @endforeach
        @endif
      </div>
    @endif
  </div>

  {{-- Tenants by bounce rate --}}
  <div class="eh-section">
    <div class="eh-section-head">
      <div class="eh-section-title">Tenants by bounce rate · last 7 days</div>
      <div class="eh-section-sub">Watch these. AWS suspends accounts above 5% sustained.</div>
    </div>
    <div class="eh-card">
      @if(count($byBounce) === 0)
        <div class="eh-empty">No bounces in the last 7 days. 🎉</div>
      @else
        <div class="eh-row eh-row-head" style="grid-template-columns: 1.4fr 90px 90px 110px 90px;">
          <div>Tenant</div><div>Bounces</div><div>Sent</div><div>Rate</div><div></div>
        </div>
        @foreach($byBounce as $row)
          <div class="eh-row" style="grid-template-columns: 1.4fr 90px 90px 110px 90px;">
            <div>
              <div style="font-weight: 500;">{{ $row['name'] }}</div>
              <div style="font-size: 11.5px; color: var(--eh-text-dim); font-family: var(--eh-mono);">{{ $row['subdomain'] ?? '—' }}.intake.works</div>
            </div>
            <div style="font-family: var(--eh-mono); font-size: 13px;">{{ $row['bounces'] }}</div>
            <div style="font-family: var(--eh-mono); font-size: 13px;">{{ $row['sent'] > 0 ? number_format($row['sent']) : '—' }}</div>
            <div style="font-family: var(--eh-mono); font-size: 13px;">{{ $row['rate'] !== null ? $row['rate'] . '%' : '—' }}</div>
            <div>
              @if($row['severity'] === 'bad')<span class="eh-pill bad">Investigate</span>
              @elseif($row['severity'] === 'warn')<span class="eh-pill warn">Watch</span>
              @elseif($row['severity'] === 'ok')<span class="eh-pill ok">OK</span>
              @else<span class="eh-pill info">Low data</span>
              @endif
            </div>
          </div>
        @endforeach
      @endif
    </div>
  </div>

  {{-- Recent events --}}
  <div class="eh-section">
    <div class="eh-section-head">
      <div class="eh-section-title">Recent bounce &amp; complaint events</div>
      <div class="eh-section-sub">Last 50 across all tenants</div>
    </div>
    <div class="eh-card">
      @if(count($recent) === 0)
        <div class="eh-empty">No bounce or complaint events yet.</div>
      @else
        <div class="eh-row eh-row-head" style="grid-template-columns: 1.6fr 100px 1fr 120px;">
          <div>Email</div><div>Type</div><div>Tenant</div><div>When</div>
        </div>
        @foreach($recent as $e)
          <div class="eh-row" style="grid-template-columns: 1.6fr 100px 1fr 120px;">
            <div class="eh-mono" style="font-size: 12.5px;">{{ $e['email'] }}</div>
            <div>
              @if($e['event_type'] === 'bounce')<span class="eh-pill bad">Bounce</span>
              @else<span class="eh-pill warn">Complaint</span>
              @endif
            </div>
            <div style="font-size: 12.5px;">{{ $e['tenant'] ?? '—' }}</div>
            <div style="font-size: 11.5px; color: var(--eh-text-dim); font-family: var(--eh-mono);">
              {{ $e['received_at']?->diffForHumans(null, true) }} ago
            </div>
          </div>
        @endforeach
      @endif
    </div>
  </div>

  <div style="font-size: 11px; color: var(--eh-text-dim); text-align: right; font-family: var(--eh-mono); margin-top: 16px;">
    Generated {{ $generatedAt->format('Y-m-d H:i:s') }} UTC
  </div>

</div>

</x-filament-panels::page>
