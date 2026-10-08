{{-- MARKER-IG-QUEUE: one prospect at a time; a person taps Follow on Instagram. No cards. --}}
@php
    $st    = $this->stats();
    $inds  = $this->industries();
    $q     = $this->queue();
    $next  = $q->first();
    $rest  = $q->slice(1);
    $done  = $this->followedToday();
    $fast   = $st['hour'] > \App\Filament\Pages\SalesInstagramFollows::PACE_WARN;
@endphp

<x-filament-panels::page>
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; font-size:14px; }
  .sx-in { background-color:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; }
  select.sx-in { padding-right:32px; background-repeat:no-repeat; }
  .sx-in option { background:#26272c; }
  .sx-inds { display:flex; gap:18px; border-bottom:1px solid var(--sx-line); overflow-x:auto; }
  .sx-inds button { background:none; border:0; border-bottom:2px solid transparent; padding:9px 0; color:var(--sx-dim); cursor:pointer; white-space:nowrap; font-weight:500; }
  .sx-inds button.on { color:#fff; border-bottom-color:var(--sx-violet); }
  .sx-inds button span { color:var(--sx-faint); font-weight:400; margin-left:4px; }
  .sx-tally { display:flex; gap:30px; flex-wrap:wrap; margin-top:16px; font-size:13px; color:var(--sx-dim); }
  .sx-tally b { display:block; font-size:22px; font-weight:650; color:#fff; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  .sx-tally .lime { color:var(--sx-lime); }
  .sx-lede { color:var(--sx-faint); font-size:13px; line-height:1.55; margin:12px 0 0; max-width:80ch; }
  .sx-lede b { color:#fff; font-weight:600; }
  .sx-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:12px 0; margin-top:14px; border-top:1px solid var(--sx-line); border-bottom:1px solid var(--sx-line); }
  .sx-tog { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--sx-dim); cursor:pointer; user-select:none; }
  .sx-tog input { accent-color:var(--sx-violet); }
  .sx-count { margin-left:auto; color:var(--sx-faint); font-size:12.5px; display:inline-flex; align-items:center; gap:6px; }
  .sx-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:6px 12px; font-weight:500; font-size:13px; cursor:pointer; white-space:nowrap; color:inherit; }
  .sx-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .sx-btn.sm { padding:4px 9px; font-size:12.5px; }
  .sx-btn.big { padding:9px 16px; font-size:14px; }
  .sx-t { width:100%; border-collapse:collapse; font-size:13.5px; }
  .sx-t th { text-align:left; font-weight:500; color:var(--sx-faint); font-size:12.5px; padding:10px; border-bottom:1px solid var(--sx-line-2); white-space:nowrap; }
  .sx-t td { padding:10px; border-bottom:1px solid var(--sx-line); vertical-align:top; }
  .sx-t .sx-sub { font-size:12px; color:var(--sx-faint); margin-top:2px; }
  .sx-t .h { color:#a78bfa; }
  .ig-next { margin-top:18px; padding:16px 0 0; border-top:1px solid var(--sx-line); }
  .ig-k { font-size:12.5px; color:var(--sx-faint); }
  .ig-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:6px; }
  .ig-who { flex:1; min-width:220px; }
  .ig-name { font-size:19px; font-weight:600; letter-spacing:-.01em; }
  .ig-sub { font-size:13px; color:var(--sx-dim); margin-top:2px; }
  .ig-keys { margin-top:10px; font-size:12px; color:var(--sx-faint); }
  .ig-keys kbd { font-family:inherit; border:1px solid var(--sx-line-2); border-radius:4px; padding:0 5px; font-size:11px; color:var(--sx-dim); }
  .ig-cols { display:grid; grid-template-columns:1.6fr 1fr; gap:32px; }
  .ig-cols > section { min-width:0; }
  .ig-warn { margin-top:16px; padding:10px 14px; border:1px solid rgba(244,124,124,.45); border-radius:8px; color:#f47c7c; font-size:13px; line-height:1.5; }
  .ig-warn b { color:#f47c7c; }
  .ig-h2 { font-size:13px; font-weight:600; color:var(--sx-dim); margin:22px 0 4px; }
  @media (max-width:760px) { .ig-cols { grid-template-columns:1fr; gap:8px; } }
</style>

<div class="sx-root"
     x-data="{
        open(url, id) {
            const w = 480, h = 820;
            const left = (screen.availLeft || 0) + screen.availWidth - w - 16;
            const top = (screen.availTop || 0) + 40;
            const win = window.open(url, 'intakeIgQueue', 'popup=yes,width=' + w + ',height=' + h + ',left=' + left + ',top=' + top);
            if (win) { win.focus(); }
            $wire.followed(id);
        },
        key(e) {
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName) || e.metaKey || e.ctrlKey || e.altKey) return;
            const b = this.$root.querySelector(e.key === 'Enter' ? '[data-ig-open]' : (e.key === 's' || e.key === 'S') ? '[data-ig-skip]' : (e.key === 'u' || e.key === 'U') ? '[data-ig-undo]' : null);
            if (b) { e.preventDefault(); b.click(); }
        }
     }"
     x-on:keydown.window="key($event)">

    @if (count($inds) > 1)
    <div class="sx-inds">
        <button type="button" class="{{ $industryId === '' ? 'on' : '' }}" wire:click="$set('industryId', '')">All</button>
        @foreach ($inds as $i)
            <button type="button" class="{{ $industryId === $i['id'] ? 'on' : '' }}" wire:click="$set('industryId', '{{ $i['id'] }}')">{{ $i['name'] }} <span>{{ number_format($i['n']) }}</span></button>
        @endforeach
    </div>
    @endif

    <div class="sx-tally">
        <div><b class="lime">{{ $st['today'] }}</b>followed today</div>
        <div><b style="{{ $fast ? 'color:#f47c7c' : '' }}">{{ $st['hour'] }}</b>in the last hour</div>
        <div><b>{{ number_format($st['queue']) }}</b>in the queue</div>
        <div><b>{{ number_format($st['all']) }}</b>followed all time</div>
    </div>

    <p class="sx-lede"><b>What this does:</b> Open loads each prospect's Instagram profile in one window beside this page. <b>You</b> tap Follow there — nothing here follows anyone automatically. Opening marks the prospect followed and logs it on their timeline; Undo if you didn't follow. A warning shows when more than {{ \App\Filament\Pages\SalesInstagramFollows::PACE_WARN }} follows land in an hour, since bursts are what Instagram blocks. Not affected: email, lead score, stage.</p>

    @if ($fast)
        {{-- MARKER-IG-PACE --}}
        <div class="ig-warn" wire:poll.60s>
            <b>Slow down:</b> {{ $st['hour'] }} follows in the last hour. Instagram action-blocks accounts that follow in bursts — take a break before the next one. This clears by itself as the hour rolls over.
        </div>
    @endif

    <div class="ig-next">
        @if (! $next)
            <div class="ig-k">Queue empty</div>
            <div class="ig-sub">Every prospect here with an Instagram link has been followed.</div>
        @else
            @php $url = \App\Filament\Pages\SalesInstagramFollows::profileUrl($next->socials); $h = \App\Filament\Pages\SalesInstagramFollows::handle($next->socials); @endphp
            <div class="ig-k">Next up</div>
            <div class="ig-row" wire:key="next-{{ $next->id }}">
                <div class="ig-who">
                    <div class="ig-name">{{ $next->shop }}</div>
                    <div class="ig-sub">{{ trim($next->city . ', ' . $next->state, ', ') }} · {{ '@' . $h }} · lead score {{ $next->lead_score }} · {{ $next->stage }}</div>
                </div>
                <button type="button" class="sx-btn p big" data-ig-open x-on:click="open({{ \Illuminate\Support\Js::from($url) }}, {{ \Illuminate\Support\Js::from($next->id) }})">Open {{ '@' . $h }} ↗</button>
                <button type="button" class="sx-btn" data-ig-skip wire:click="skip('{{ $next->id }}')">Skip</button>
                <button type="button" class="sx-btn" wire:click="wrongAccount('{{ $next->id }}')">Wrong account</button>
            </div>
            <div class="ig-keys"><kbd>Enter</kbd> open next · <kbd>S</kbd> skip · <kbd>U</kbd> undo last</div>
        @endif
    </div>

    <div class="sx-bar">
        <select class="sx-in" wire:model.live="state">
            <option value="">All states</option>
            @foreach ($this->states() as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
        </select>
        <select class="sx-in" wire:model.live="stage">
            <option value="">Any stage</option>
            @foreach (\App\Models\SalesProspect::STAGES as $k => $label)
                @if (! in_array($k, ['won', 'lost'], true))<option value="{{ $k }}">{{ is_array($label) ? ($label['label'] ?? $k) : $label }}</option>@endif
            @endforeach
        </select>
        <select class="sx-in" wire:model.live="sort">
            <option value="score">Highest lead score first</option>
            <option value="new">Newest first</option>
        </select>
        <label class="sx-tog"><input type="checkbox" wire:model.live="withRep"> Only with a rep assigned</label>
    </div>

    <div class="ig-cols">
        <section>
            <h2 class="ig-h2">Queue</h2>
            <table class="sx-t">
                <thead><tr><th>Shop</th><th>Score</th><th>Rep</th></tr></thead>
                <tbody>
                @forelse ($rest as $p)
                    <tr wire:key="q-{{ $p->id }}">
                        <td>{{ $p->shop }}<div class="sx-sub">{{ trim($p->city . ', ' . $p->state, ', ') }} · <span class="h">{{ '@' . \App\Filament\Pages\SalesInstagramFollows::handle($p->socials) }}</span>{{ $p->ig_skipped_at ? ' · skipped' : '' }}</div></td>
                        <td>{{ $p->lead_score }}</td>
                        <td style="color:var(--sx-dim)">{{ $p->rep?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="sx-sub">Nothing else waiting.</td></tr>
                @endforelse
                @if ($st['queue'] > $q->count())
                    <tr><td colspan="3" class="sx-sub">+ {{ number_format($st['queue'] - $q->count()) }} more</td></tr>
                @endif
                </tbody>
            </table>
        </section>
        <section>
            <h2 class="ig-h2">Followed today</h2>
            <table class="sx-t">
                <thead><tr><th>Shop</th><th style="text-align:right">When</th></tr></thead>
                <tbody>
                @forelse ($done as $i => $p)
                    <tr wire:key="d-{{ $p->id }}">
                        <td>{{ $p->shop }}</td>
                        <td style="text-align:right;white-space:nowrap;color:var(--sx-dim)">
                            @if ($i === 0)<button type="button" class="sx-btn sm" data-ig-undo wire:click="undo('{{ $p->id }}')" style="margin-right:8px">Undo</button>@endif
                            {{ \Carbon\Carbon::parse($p->ig_followed_at)->timezone(\App\Filament\Pages\SalesInstagramFollows::TZ)->format('g:i') }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="sx-sub">None yet today.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>
</x-filament-panels::page>
