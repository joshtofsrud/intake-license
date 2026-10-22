{{-- MARKER-EMAIL-OUTREACH: one prospect at a time; a person reads the email and taps Send. Same look as Instagram follows. No cards. --}}
@php
    $st    = $this->stats();
    $inds  = $this->industries();
    $cur   = $this->current();
    $q     = $this->queue();
    $done  = $this->sentToday();
    $tpls  = $this->templates();
    $lp    = $this->landingPages();
    $tplUrl = \App\Filament\Pages\SalesEmailTemplates::getUrl();
@endphp

<x-filament-panels::page>
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; font-size:14px; }
  .sx-in { background-color:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; }
  select.sx-in { padding-right:32px; background-repeat:no-repeat; }
  .sx-in option, .sx-in optgroup { background:#26272c; }
  .sx-inds { display:flex; gap:18px; border-bottom:1px solid var(--sx-line); overflow-x:auto; }
  .sx-inds button { background:none; border:0; border-bottom:2px solid transparent; padding:9px 0; color:var(--sx-dim); cursor:pointer; white-space:nowrap; font-weight:500; }
  .sx-inds button.on { color:#fff; border-bottom-color:var(--sx-violet); }
  .sx-inds button span { color:var(--sx-faint); font-weight:400; margin-left:4px; }
  .sx-tally { display:flex; gap:30px; flex-wrap:wrap; margin-top:16px; font-size:13px; color:var(--sx-dim); }
  .sx-tally b { display:block; font-size:22px; font-weight:650; color:#fff; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  .sx-tally .lime { color:var(--sx-lime); }
  .sx-lede { color:var(--sx-faint); font-size:13px; line-height:1.55; margin:12px 0 0; max-width:84ch; }
  .sx-lede b { color:#fff; font-weight:600; }
  .sx-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:12px 0; margin-top:14px; border-top:1px solid var(--sx-line); border-bottom:1px solid var(--sx-line); }
  .sx-count { margin-left:auto; color:var(--sx-faint); font-size:12.5px; }
  .sx-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:6px 12px; font-weight:500; font-size:13px; cursor:pointer; white-space:nowrap; color:inherit; }
  .sx-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .sx-btn.sm { padding:4px 9px; font-size:12.5px; }
  .sx-btn.big { padding:9px 16px; font-size:14px; }
  .sx-btn[disabled] { opacity:.55; cursor:default; }
  .sx-t { width:100%; border-collapse:collapse; font-size:13.5px; }
  .sx-t th { text-align:left; font-weight:500; color:var(--sx-faint); font-size:12.5px; padding:10px; border-bottom:1px solid var(--sx-line-2); white-space:nowrap; }
  .sx-t td { padding:10px; border-bottom:1px solid var(--sx-line); vertical-align:top; }
  .sx-t .sx-sub { font-size:12px; color:var(--sx-faint); margin-top:2px; }
  .ig-k { font-size:12.5px; color:var(--sx-faint); }
  .ig-name { font-size:19px; font-weight:600; letter-spacing:-.01em; }
  .ig-sub { font-size:13px; color:var(--sx-dim); margin-top:2px; }
  .ig-keys { margin-top:10px; font-size:12px; color:var(--sx-faint); }
  .ig-keys kbd { font-family:inherit; border:1px solid var(--sx-line-2); border-radius:4px; padding:0 5px; font-size:11px; color:var(--sx-dim); }
  .ig-h2 { font-size:13px; font-weight:600; color:var(--sx-dim); margin:22px 0 4px; }
  .eo-cols { display:grid; grid-template-columns:1.25fr 1fr; gap:36px; margin-top:18px; }
  .eo-cols > section { min-width:0; }
  .eo-two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .eo-lbl { font-size:12.5px; color:var(--sx-faint); margin:14px 0 4px; }
  .eo-lbl a { color:#a78bfa; }
  .eo-full { width:100%; }
  textarea.sx-in { line-height:1.55; resize:vertical; font-family:inherit; }
  .eo-link { margin-top:8px; font-size:12.5px; color:var(--sx-dim); line-height:1.5; }
  .eo-link code { color:#a78bfa; font-size:12.5px; word-break:break-all; font-family:inherit; }
  .eo-chips { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:6px; font-size:12px; color:var(--sx-faint); }
  .eo-chip { border:1px solid var(--sx-line-2); border-radius:999px; padding:2px 10px; font-size:12px; color:var(--sx-dim); background:none; cursor:pointer; }
  .eo-chip:hover { border-color:var(--sx-violet); color:#fff; }
  .eo-err { color:#f47c7c; font-size:12px; margin-top:4px; }
  .eo-warn { color:#fbbf24; font-size:12px; margin-top:6px; }
  .eo-pv-h { font-size:12.5px; color:var(--sx-faint); margin-bottom:6px; display:flex; justify-content:space-between; gap:8px; }
  .eo-pv { border:1px solid var(--sx-line-2); border-radius:8px; background:#fff; color:#111; padding:16px 18px; overflow-wrap:anywhere; }
  .eo-pv a { pointer-events:none; }
  .eo-pv-s { font-weight:600; font-size:15px; margin-bottom:12px; color:#111; }
  .eo-yes { color:var(--sx-lime); }
  @media (max-width:860px) { .eo-cols, .eo-two { grid-template-columns:1fr; gap:10px; } }
</style>

<div class="sx-root"
     x-data="{
        ins(tok) {
            const t = this.$refs.body; if (! t) return;
            t.setRangeText(tok, t.selectionStart, t.selectionEnd, 'end');
            t.dispatchEvent(new Event('input', { bubbles: true }));
            t.focus();
        },
        key(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { const b = this.$root.querySelector('[data-eo-send]'); if (b && ! b.disabled) { e.preventDefault(); b.click(); } return; }
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName) || e.metaKey || e.ctrlKey || e.altKey) return;
            if (e.key === 's' || e.key === 'S') { const b = this.$root.querySelector('[data-eo-skip]'); if (b) { e.preventDefault(); b.click(); } }
        }
     }"
     x-on:keydown.window="key($event)">

    {{-- MARKER-OUTREACH-RESULTS: Send | Results --}}
    <div class="sx-inds" style="margin-bottom:6px">
        <button type="button" class="{{ $tab === 'send' ? 'on' : '' }}" wire:click="setTab('send')" style="font-size:15px">Send</button>
        <button type="button" class="{{ $tab === 'results' ? 'on' : '' }}" wire:click="setTab('results')" style="font-size:15px">Results</button>
    </div>

    @if ($tab === 'results')
        @include('filament.partials.outreach-results')
    @else

    @if (count($inds) > 1)
    <div class="sx-inds">
        <button type="button" class="{{ $industryId === '' ? 'on' : '' }}" wire:click="$set('industryId', '')">All <span>{{ number_format($st['total']) }}</span></button>
        @foreach ($inds as $i)
            <button type="button" class="{{ $industryId === $i['id'] ? 'on' : '' }}" wire:click="$set('industryId', '{{ $i['id'] }}')">{{ $i['name'] }} <span>{{ number_format($i['n']) }}</span></button>
        @endforeach
    </div>
    @endif

    <div class="sx-tally">
        <div><b class="lime">{{ $st['today'] }}</b>emailed today</div>
        <div><b>{{ number_format($st['all']) }}</b>emailed all time</div>
        <div><b>{{ number_format($st['visited']) }}</b>visited from their link</div>
        <div><b>{{ number_format($st['queue']) }}</b>in the queue</div>
    </div>

    <p class="sx-lede"><b>What this does:</b> Send emails the shop shown, from Intake's platform email address, with an unsubscribe link and your postal address. Nothing sends on its own. <b>[link]</b> becomes that shop's own link to the page you pick, so their visit shows on their timeline with the pages they looked at. Your template, landing page and filters are remembered. Addresses that unsubscribed, bounced or complained never appear here. Visits while you're signed in to master admin aren't recorded, so test a link in a private window. <b>Send test to me</b> sends this email only to you, with a link that carries no tracking code. The signature is set on Email templates › Signature. <b>Not affected:</b> tenant emails (receipts, bookings), Instagram follows, campaigns.</p>

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
            <option value="emailed">Longest since emailed</option>
            <option value="new">Newest first</option>
        </select>
        {{-- MARKER-OUTREACH-RECONTACT --}}
        <select class="sx-in" wire:model.live="who">
            <option value="new">Not emailed yet</option>
            <option value="again">Emailed before</option>
            <option value="all">Everyone</option>
        </select>
        <span class="sx-count">{{ ['new' => "Shops with an email address that haven't been emailed", 'again' => 'Shops you emailed before, longest ago first is a sort option', 'all' => 'Every shop with an email address'][$who] ?? '' }}</span>
    </div>

    <div class="eo-cols">
        <section>
            @if (! $cur)
                <div class="ig-k">Queue empty</div>
                <div class="ig-sub">{{ $who === 'new' ? 'Every prospect here with an email address has been emailed. Switch "Not emailed yet" to "Emailed before" to email them again, change the filters, or add email addresses on Prospects.' : 'Nobody matches these filters. A shop you emailed in the last hour waits an hour before it comes back.' }}</div>
            @else
                <div wire:key="cur-{{ $cur->id }}">
                    <div class="ig-k">Next up</div>
                    <div class="ig-name">{{ $cur->shop }}</div>
                    <div class="ig-sub">{{ trim($cur->city . ', ' . $cur->state, ', ') }}{{ $cur->owner_contact ? ' · ' . $cur->owner_contact : '' }} · {{ $cur->email }} · lead score {{ $cur->lead_score }} · {{ \App\Models\SalesProspect::STAGES[$cur->stage] ?? $cur->stage }}{{ $cur->email_skipped_at ? ' · skipped before' : '' }}{{ $cur->emailed_at ? ' · last emailed ' . $cur->emailed_at->diffForHumans() : '' }}</div>
                </div>

                <div class="eo-two">
                    <div>
                        <div class="eo-lbl">Template · <a href="{{ $tplUrl }}">edit templates</a></div>
                        <select class="sx-in eo-full" wire:model.live="templateId">
                            @if ($tpls->isEmpty())<option value="">No templates yet</option>@else<option value="">Blank</option>@endif
                            @foreach ($tpls as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <div class="eo-lbl"><span>Their link opens</span></div>
                        <select class="sx-in eo-full" wire:model.live="landing">
                            <optgroup label="Pages">
                                @foreach ($lp['pages'] as $path => $label)<option value="{{ $path }}">{{ $label }}{{ $path !== '/' ? ' · ' . $path : '' }}</option>@endforeach
                            </optgroup>
                            @if (count($lp['industries']))
                            <optgroup label="Industry pages">
                                @foreach ($lp['industries'] as $path => $label)<option value="{{ $path }}">{{ $label }} · {{ $path }}</option>@endforeach
                            </optgroup>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="eo-link">Their link: <code>{{ $this->link($cur) }}</code><br>
                    <span style="color:var(--sx-faint)">Only for this shop. Only published pages are listed; publish a page in Site &amp; content to add it.</span></div>

                <div class="eo-lbl"><span>Subject</span></div>
                <input type="text" class="sx-in eo-full" wire:model.live.debounce.400ms="subject" placeholder="Subject">
                @error('subject')<div class="eo-err">{{ $message }}</div>@enderror

                <div class="eo-lbl"><span>Message</span></div>
                <textarea class="sx-in eo-full" rows="11" x-ref="body" wire:model.live.debounce.400ms="body"></textarea>
                @error('body')<div class="eo-err">{{ $message }}</div>@enderror
                <div class="eo-chips">Insert:
                    @foreach (\App\Services\Sales\ProspectOutreach::TOKENS as $tok)
                        <button type="button" class="eo-chip" x-on:click="ins('{{ $tok }}')">{{ $tok }}</button>
                    @endforeach
                </div>
                @if (! str_contains($body, '[link]'))
                    <div class="eo-warn">No [link] in this message, so it won't carry their link and a visit can't be traced to them.</div>
                @endif
                @if ($cur->owner_contact === null || trim((string) $cur->owner_contact) === '')
                    <div class="ig-keys" style="margin-top:6px">No owner name on file: [first_name] is left out, so "Hi [first_name]," reads "Hi,".</div>
                @endif

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:16px">
                    <button type="button" class="sx-btn p big" data-eo-send wire:click="send" wire:loading.attr="disabled" wire:target="send">Send to {{ $cur->email }}</button>
                    <button type="button" class="sx-btn" data-eo-skip wire:click="skip" wire:loading.attr="disabled">Skip</button>
                    <button type="button" class="sx-btn" wire:click="sendTest" wire:loading.attr="disabled" title="Sends this email only to you, marked [Test]. The shop stays in the queue.">Send test to me</button>
                    <button type="button" class="sx-btn" wire:click="wrongEmail" wire:loading.attr="disabled">Wrong email</button>
                </div>
                <div class="ig-keys"><kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>Enter</kbd> send · <kbd>S</kbd> skip</div>
            @endif
        </section>

        <section>
            @if ($cur)
                <div class="eo-pv-h"><span>Preview, as they'll see it</span><span>To: {{ $cur->email }}</span></div>
                <div class="eo-pv">
                    <div class="eo-pv-s">{{ $this->previewSubject($cur) ?: '(no subject)' }}</div>
                    {!! $this->previewHtml($cur) !!}
                </div>
            @endif

            <h2 class="ig-h2">Queue</h2>
            <table class="sx-t">
                <thead><tr><th>Shop</th><th style="text-align:right">Score</th></tr></thead>
                <tbody>
                @forelse ($q as $p)
                    <tr wire:key="q-{{ $p->id }}">
                        <td>{{ $p->shop }}<div class="sx-sub">{{ trim($p->city . ', ' . $p->state, ', ') }} · {{ $p->email }}{{ $p->email_skipped_at ? ' · skipped' : '' }}{{ $p->emailed_at ? ' · emailed ' . $p->emailed_at->diffForHumans() : '' }}</div></td>
                        <td style="text-align:right">{{ $p->lead_score }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="sx-sub">Nothing else waiting.</td></tr>
                @endforelse
                @if ($st['queue'] > $q->count() + ($cur ? 1 : 0))
                    <tr><td colspan="2" class="sx-sub">+ {{ number_format($st['queue'] - $q->count() - ($cur ? 1 : 0)) }} more</td></tr>
                @endif
                </tbody>
            </table>

            <h2 class="ig-h2">Emailed today</h2>
            <table class="sx-t">
                <thead><tr><th>Shop</th><th style="text-align:right">When</th></tr></thead>
                <tbody>
                @forelse ($done as $d)
                    <tr>
                        <td>{{ $d->shop }}@if ($d->link_clicked_at)<div class="sx-sub eo-yes">Visited from their link</div>@endif</td>
                        <td style="text-align:right;white-space:nowrap;color:var(--sx-dim)">{{ \Carbon\Carbon::parse($d->created_at)->timezone(\App\Filament\Pages\SalesEmailOutreach::TZ)->format('g:i a') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="sx-sub">None yet today.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
    @endif
</div>
</x-filament-panels::page>
