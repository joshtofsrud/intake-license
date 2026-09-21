{{-- MARKER-CL-RM-LAYOUT — one highlight card, used by the strip and its expand. --}}
<div style="background:var(--mk-bg2);border:0.5px solid var(--mk-border);border-radius:12px;padding:15px 16px">
  <span style="display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mk-accent);border:0.5px solid var(--mk-accent);border-radius:99px;padding:1px 8px;margin-bottom:9px">Highlight</span>
  <div style="font-weight:700;font-size:15.5px;line-height:1.3;margin-bottom:5px">{{ $h->title }}</div>
  <div style="font-size:13px;color:var(--mk-muted);line-height:1.5">{{ $h->body }}</div>
  <div style="font-size:11.5px;color:var(--mk-muted);margin-top:9px;opacity:.8">
    {{ $h->shipped_on ? $h->shipped_on->format('M j') : '' }}{{ $h->shipped_on && $h->category ? ' · ' : '' }}{{ $h->category }}
  </div>
</div>
