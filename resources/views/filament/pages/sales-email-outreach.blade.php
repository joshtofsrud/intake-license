{{-- MARKER-EMAIL-OUTREACH: one prospect at a time; a person reads the email and taps Send. Same look as Instagram follows. No cards. --}}
@php
    $st    = $this->stats();
    $inds  = $this->industries();
    $cur   = $this->current();
    $q     = $this->queue();
    $done  = $list === 'today' ? $this->sentToday() : collect();
    $seen  = $list === 'visited' ? $this->visited() : collect();
    $news  = $list === 'today' ? $this->threadNews($done) : [];
    $tz    = \App\Filament\Pages\SalesEmailOutreach::TZ;
    $listTotal = ['queue' => max(0, $st['queue'] - ($cur ? 1 : 0)), 'today' => $st['today'], 'visited' => $st['visited']][$list];
    $per   = \App\Filament\Pages\SalesEmailOutreach::PER_PAGE;
    $pages = max(1, (int) ceil($listTotal / $per));
    $tpls  = $this->templates();
    $lp    = $this->landingPages();
    $tplUrl = \App\Filament\Pages\SalesEmailTemplates::getUrl();
@endphp

<x-filament-panels::page>
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; font-size:14px; }
  .sx-in { background-color:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; }
  /* MARKER-OUTREACH-LAYOUT: the app's own chevron, not the browser's arrow */
  select.sx-in { -webkit-appearance:none; appearance:none; padding-right:30px; background-repeat:no-repeat; background-position:right 10px center; background-size:12px 12px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' fill='none' stroke='%23a3a3ab' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 4.5l3 3 3-3'/%3E%3C/svg%3E"); }
  .sx-in option, .sx-in optgroup { background:#26272c; }
  .sx-inds { display:flex; gap:18px; border-bottom:1px solid var(--sx-line); overflow-x:auto; }
  .sx-inds button { background:none; border:0; border-bottom:2px solid transparent; padding:9px 0; color:var(--sx-dim); cursor:pointer; white-space:nowrap; font-weight:500; }
  .sx-inds button.on { color:#fff; border-bottom-color:var(--sx-violet); }
  .sx-inds button span { color:var(--sx-faint); font-weight:400; margin-left:4px; }
  .sx-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:12px 0; margin-top:14px; border-top:1px solid var(--sx-line); border-bottom:1px solid var(--sx-line); }
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
  .eo-cols { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:28px; margin-top:18px; }
  .eo-pvcol { position:sticky; top:16px; align-self:start; }
  .eo-pvcol .eo-pv { max-height:calc(100vh - 140px); overflow:auto; }
  .eo-bar-tally { margin-left:auto; display:flex; gap:20px; flex-wrap:wrap; font-size:12.5px; color:var(--sx-dim); }
  .eo-bar-tally b { color:#fff; font-weight:600; font-size:15px; margin-right:4px; font-variant-numeric:tabular-nums; }
  .eo-bar-tally b.lime { color:var(--sx-lime); }
  .eo-legend { color:var(--sx-faint); font-size:12.5px; line-height:1.55; padding:10px 0; border-bottom:1px solid var(--sx-line); }
  .eo-legend b { color:var(--sx-dim); font-weight:500; }
  .eo-legend button { background:none; border:0; color:#a78bfa; font-size:12.5px; cursor:pointer; padding:0; }
  .eo-top { display:flex; align-items:flex-start; gap:12px; }
  .eo-top .ig-nav { margin-left:auto; font-size:12.5px; color:var(--sx-faint); white-space:nowrap; padding-top:2px; }
  .eo-list { margin-top:34px; }
  .eo-list .sx-inds { align-items:flex-end; }
  .eo-list .sx-t td { vertical-align:middle; white-space:nowrap; }
  .eo-list .sx-t td.eo-shop { white-space:normal; }
  .eo-list .sx-t tr.cur td { background:var(--sx-vsoft); }
  .eo-list .eo-em { color:#a78bfa; }
  .eo-pager { display:flex; justify-content:space-between; align-items:center; padding:10px 0; font-size:12.5px; color:var(--sx-faint); }
  .eo-tags { display:inline-flex; gap:6px; flex-wrap:wrap; }
  .eo-tag-o { background:var(--sx-vsoft) !important; color:#c4b5fd !important; }
  .eo-tag-n { background:none !important; color:var(--sx-faint) !important; border:1px solid var(--sx-line-2); font-weight:500 !important; }
  .eo-tag { display:inline-block; border-radius:4px; padding:1px 7px; font-size:11px; font-weight:600; background:rgba(190,242,100,.14); color:var(--sx-lime); }
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
  /* MARKER-OUTREACH-AUTO */
  .eo-auto { padding:12px 0; border-bottom:1px solid var(--sx-line); }
  .eo-auto-row { display:flex; align-items:center; gap:8px 16px; flex-wrap:wrap; font-size:13px; color:var(--sx-dim); }
  .eo-auto-row b { color:#fff; font-weight:600; }
  .eo-auto-row label { display:inline-flex; align-items:center; gap:6px; }
  .eo-num { width:66px; padding:4px 8px; }
  .eo-auto-sub { font-size:12.5px; color:var(--sx-faint); margin-top:6px; line-height:1.55; }
  .eo-auto-sub b { color:var(--sx-dim); font-weight:500; }
  .eo-auto-dot { width:8px; height:8px; border-radius:50%; background:var(--sx-lime); box-shadow:0 0 0 3px rgba(190,242,100,.18); }
  @media (max-width:860px) { .eo-cols, .eo-two { grid-template-columns:1fr; gap:10px; } .eo-pvcol { position:static; } .eo-pvcol .eo-pv { max-height:none; } .eo-bar-tally { margin-left:0; width:100%; } .eo-hide-m { display:none; } }
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

    <div class="sx-bar">
        <select class="sx-in" wire:model.live="state">
            <option value="">All states</option>
            {{-- MARKER-OUTREACH-STATE-LIST: the remembered state stays listed even
                 when it has no shops left. Without it the dropdown showed
                 "All states" while still filtering on that state, the queue
                 read 0, and picking "All states" changed nothing. --}}
            @php $stList = $this->states(); @endphp
            @if ($state !== '' && ! in_array($state, $stList, true))
                <option value="{{ $state }}">{{ $state }} (none left)</option>
            @endif
            @foreach ($stList as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
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
        {{-- MARKER-OUTREACH-AUTO: business addresses vs free email (Gmail, Outlook…) --}}
        <select class="sx-in" wire:model.live="mail">
            @foreach (\App\Services\Sales\OutreachQueue::MAIL as $mk => $ml)<option value="{{ $mk }}">{{ $ml }}</option>@endforeach
        </select>
        <button type="button" class="sx-btn sm" wire:click="$toggle('showDomains')">{{ $showDomains ? 'Close list' : 'Free email list' }}</button>
        <div class="eo-bar-tally">
            <span><b class="lime">{{ $st['today'] }}</b>today</span>
            <span><b>{{ number_format($st['all']) }}</b>all time</span>
            <span><b>{{ number_format($st['visited']) }}</b>visited</span>
            <span><b>{{ number_format($st['queue']) }}</b>in queue</span>
        </div>
    </div>

    @if ($showDomains)
        <div class="eo-legend">
            <b>Free email domains.</b> An address at one of these counts as free email for the filter and for auto-send's slower pace; everything else is a business address. One per line.
            <textarea class="sx-in eo-full" rows="6" wire:model="freeDomainsText" style="margin-top:8px;font-family:inherit"></textarea>
            <div style="display:flex;gap:8px;margin-top:8px"><button type="button" class="sx-btn p sm" wire:click="saveFreeDomains">Save list</button><button type="button" class="sx-btn sm" wire:click="$toggle('showDomains')">Cancel</button></div>
        </div>
    @endif

    {{-- MARKER-OUTREACH-AUTO: auto-send --}}
    @php $au = $this->autoState(); $auOn = (bool) ($au['running'] ?? false); @endphp
    <div class="eo-auto" @if ($auOn) wire:poll.5s @endif>
        @if ($auOn)
            @php $ac = $au['config']; $next = isset($au['next_at']) ? \Carbon\Carbon::parse($au['next_at']) : null; @endphp
            <div class="eo-auto-row">
                <span class="eo-auto-dot"></span>
                <b>Auto-send is on</b>
                <span>{{ number_format($au['sent']['business'] + $au['sent']['free']) }} sent ({{ $au['sent']['business'] }} business · {{ $au['sent']['free'] }} free email){{ $au['skipped'] ? ' · ' . $au['skipped'] . ' skipped' : '' }}</span>
                <span>{{ $next && $next->isFuture() ? 'next check in ' . max(1, (int) now()->diffInSeconds($next)) . 's' : 'sending' }}</span>
                <button type="button" class="sx-btn sm" style="margin-left:auto;border-color:#f47c7c;color:#f47c7c" wire:click="stopAuto">Stop auto-send</button>
            </div>
            <div class="eo-auto-sub">
                Template “{{ $ac['template'] }}” · lands on {{ \App\Services\Sales\ProspectOutreach::pageLabel($ac['page']) }} ·
                business every {{ $ac['gap_business'] }}s{{ $ac['cap_business'] ? ', ' . $ac['cap_business'] . '/day' : '' }} ·
                free email every {{ $ac['gap_free'] }}s{{ $ac['cap_free'] ? ', ' . $ac['cap_free'] . '/day' : '' }} ·
                {{ $au['bounces'] ?? 0 }} {{ ($au['bounces'] ?? 0) === 1 ? 'bounce' : 'bounces' }} so far{{ $ac['max_bounces'] ? ' (pauses at ' . $ac['max_bounces'] . ')' : '' }} ·
                started {{ \Carbon\Carbon::parse($au['started_at'])->setTimezone($tz)->format('g:i a') }} by {{ $au['started_by_name'] }}
                @if (! empty($au['last_sent'])) · last: {{ $au['last_sent']['shop'] }}, {{ \Carbon\Carbon::parse($au['last_sent']['at'])->diffForHumans() }}@endif
            </div>
            <div class="eo-auto-sub">It uses the filters, template and landing page it was started with, and keeps going if you close this page. Sending by hand still works alongside it.</div>
        @else
            <div class="eo-auto-row">
                <b>Auto-send</b>
                <label>Business: one every <input type="number" min="1" max="3600" class="sx-in eo-num" wire:model.live.debounce.500ms="gapBusiness"> s</label>
                <label>Free email: one every <input type="number" min="1" max="3600" class="sx-in eo-num" wire:model.live.debounce.500ms="gapFree"> s</label>
                <label>Daily cap <input type="number" min="0" class="sx-in eo-num" wire:model="capBusiness"> / <input type="number" min="0" class="sx-in eo-num" wire:model="capFree"></label>
                <label>Pause after <input type="number" min="0" class="sx-in eo-num" wire:model="maxBounces"> bounces</label>
                <button type="button" class="sx-btn p sm" style="margin-left:auto" wire:click="startAuto"
                    data-confirm="Start auto-send?&#10;It emails the {{ number_format($st['queue']) }} {{ \Illuminate\Support\Str::plural('shop', $st['queue']) }} in this queue ({{ \App\Services\Sales\OutreachQueue::MAIL[$mail] }}) with the saved template, one business address every {{ $gapBusiness }}s and one free-email address every {{ $gapFree }}s, until the queue runs out or you stop it. It keeps going if you close this page.">Start auto-send</button>
            </div>
            <div class="eo-auto-sub">
                Sends this queue (the filters above) with the <b>saved</b> template and landing page below, not edits on screen. Gmail, Outlook and other free email get the slower pace. Caps are per day, business / free email, 0 = no cap. It stops by itself when the queue runs out, the caps are reached, a send fails, or bounces and spam complaints reach the limit (0 = never pause).
                @if (! empty($au['stopped_reason']))
                    <br><span class="eo-warn" style="display:inline">Last run: {{ $au['stopped_reason'] }} {{ number_format(($au['sent']['business'] ?? 0) + ($au['sent']['free'] ?? 0)) }} sent, ended {{ \Carbon\Carbon::parse($au['stopped_at'])->setTimezone($tz)->format('M j, g:i a') }}.</span>
                @endif
            </div>
        @endif
    </div>

    {{-- MARKER-OUTREACH-LAYOUT: the legend, one line until More --}}
    <div class="eo-legend" x-data="{ more: false }">
        <b>Sends when you press Send, or on its own while auto-send is on,</b> from Intake's platform address, with an unsubscribe link and your postal address. Addresses that unsubscribed, bounced or complained never appear. <b>Not affected:</b> tenant emails (receipts, bookings), Instagram follows, campaigns.
        <button type="button" x-on:click="more = ! more" x-text="more ? 'Less' : 'More'">More</button>
        <div x-show="more" x-cloak style="margin-top:6px"><b>[link]</b> becomes that shop's own link to the page you pick, so their visit shows on their timeline with the pages they looked at. Your template, landing page and filters are remembered. Visits while you're signed in to master admin aren't recorded, so test a link in a private window. <b>Send test</b> sends this email only to you (marked [Test]), or to the address you type beside it, such as a spam checker like mail-tester.com or your own Gmail and Outlook inboxes, exactly as a shop would get it. Its link carries no tracking code and the shop stays in the queue. The signature is set on Email templates › Signature. {{ ['new' => "The queue is shops with an email address that haven't been emailed.", 'again' => 'The queue is shops you emailed before; "Longest since emailed" is a sort option.', 'all' => 'The queue is every shop with an email address.'][$who] ?? '' }}</div>
    </div>

    <div class="eo-cols">
        <section>
            @if (! $cur)
                <div class="ig-k">Queue empty</div>
                <div class="ig-sub">{{ $who === 'new' ? 'Every prospect here with an email address has been emailed. Switch "Not emailed yet" to "Emailed before" to email them again, change the filters, or add email addresses on Prospects.' : 'Nobody matches these filters. A shop you emailed in the last hour waits an hour before it comes back.' }}</div>
            @else
                <div wire:key="cur-{{ $cur->id }}">
                    <div class="ig-k">{{ $this->onTop() ? 'Next up' : 'Writing to' }} · {{ number_format($st['queue']) }} in the queue</div>
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
                <textarea class="sx-in eo-full" rows="16" x-ref="body" wire:model.live.debounce.400ms="body"></textarea>
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
                    {{-- MARKER-OUTREACH-TEST-TO --}}
                    <span style="display:inline-flex;gap:6px;align-items:center">
                        <input type="email" class="sx-in" style="width:210px" wire:model="testTo" placeholder="Test to: {{ auth()->user()?->email }}" aria-label="Send a test to this address" title="Blank sends it to you, marked [Test]. Any other address (a spam checker like mail-tester.com, or your own Gmail or Outlook) gets it exactly as a shop would, without [Test]. The shop stays in the queue either way.">
                        <button type="button" class="sx-btn" wire:click="sendTest" wire:loading.attr="disabled">Send test</button>
                    </span>
                    <button type="button" class="sx-btn" wire:click="wrongEmail" wire:loading.attr="disabled">Wrong email</button>
                </div>
                <div class="ig-keys"><kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>Enter</kbd> send · <kbd>S</kbd> skip</div>
            @endif
        </section>

        <section class="eo-pvcol">
            @if ($cur)
                <div class="eo-pv-h"><span>Preview, as they'll see it</span><span>To: {{ $cur->email }}</span></div>
                <div class="eo-pv">
                    <div class="eo-pv-s">{{ $this->previewSubject($cur) ?: '(no subject)' }}</div>
                    {!! $this->previewHtml($cur) !!}
                </div>
            @endif
        </section>
    </div>

    {{-- MARKER-OUTREACH-LAYOUT: one full-width list, 10 a page --}}
    <div class="eo-list">
        <div class="sx-inds">
            <button type="button" class="{{ $list === 'queue' ? 'on' : '' }}" wire:click="setList('queue')">Queue <span>{{ number_format(max(0, $st['queue'] - ($cur ? 1 : 0))) }}</span></button>
            <button type="button" class="{{ $list === 'today' ? 'on' : '' }}" wire:click="setList('today')">Emailed today <span>{{ $st['today'] }}</span></button>
            <button type="button" class="{{ $list === 'visited' ? 'on' : '' }}" wire:click="setList('visited')">Visited <span>{{ number_format($st['visited']) }}</span></button>
        </div>
        <table class="sx-t">
        @if ($list === 'queue')
            <thead><tr><th>Shop</th><th>Town</th><th class="eo-hide-m">Email</th><th style="text-align:right">Score</th><th></th></tr></thead>
            <tbody>
            @forelse ($q as $p)
                <tr wire:key="q-{{ $p->id }}">
                    <td class="eo-shop">{{ $p->shop }}@if ($p->email_skipped_at || $p->emailed_at)<div class="sx-sub">{{ $p->email_skipped_at ? 'skipped' : '' }}{{ $p->email_skipped_at && $p->emailed_at ? ' · ' : '' }}{{ $p->emailed_at ? 'emailed ' . $p->emailed_at->diffForHumans() : '' }}</div>@endif</td>
                    <td style="color:var(--sx-dim)">{{ trim($p->city . ', ' . $p->state, ', ') }}</td>
                    <td class="eo-hide-m eo-em">{{ $p->email }}</td>
                    <td style="text-align:right">{{ $p->lead_score }}</td>
                    <td style="text-align:right"><button type="button" class="sx-btn sm" wire:click="pick('{{ $p->id }}')">Write</button></td>
                </tr>
            @empty
                <tr><td colspan="5" class="sx-sub">Nothing else waiting.</td></tr>
            @endforelse
            </tbody>
        @elseif ($list === 'today')
            <thead><tr><th>Shop</th><th>Town</th><th>Status</th><th style="text-align:right">Sent</th></tr></thead>
            <tbody>
            @forelse ($done as $d)
                <tr>
                    <td class="eo-shop">{{ $d->shop }}</td>
                    <td style="color:var(--sx-dim)">{{ trim($d->city . ', ' . $d->state, ', ') }}</td>
                    @php $nw = $news[(string) $d->thread_id] ?? []; @endphp
                    <td><span class="eo-tags">
                        @if (! empty($nw['replied']))<span class="eo-tag">Replied {{ $nw['replied']->timezone($tz)->format('g:i a') }}</span>@endif
                        @if ($d->link_clicked_at)<span class="eo-tag">Visited from their link</span>@endif
                        @if (! empty($nw['opened']))<span class="eo-tag eo-tag-o">Opened {{ $nw['opened']->timezone($tz)->format('g:i a') }}</span>@else<span class="eo-tag eo-tag-n">Not opened</span>@endif
                        @if (! empty($nw['auto']) && empty($nw['replied']))<span class="eo-tag eo-tag-n">Auto-reply</span>@endif
                    </span></td>
                    <td style="text-align:right;color:var(--sx-dim)">{{ \Carbon\Carbon::parse($d->created_at)->timezone(\App\Filament\Pages\SalesEmailOutreach::TZ)->format('g:i a') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="sx-sub">None yet today.</td></tr>
            @endforelse
            </tbody>
        @else
            <thead><tr><th>Shop</th><th>Town</th><th>Emailed</th><th style="text-align:right">Visited</th></tr></thead>
            <tbody>
            @forelse ($seen as $v)
                <tr>
                    <td class="eo-shop">{{ $v->shop }}</td>
                    <td style="color:var(--sx-dim)">{{ trim($v->city . ', ' . $v->state, ', ') }}</td>
                    <td style="color:var(--sx-dim)">{{ $v->emailed_at ? $v->emailed_at->timezone(\App\Filament\Pages\SalesEmailOutreach::TZ)->format('M j, g:i a') : '—' }}</td>
                    <td style="text-align:right;color:var(--sx-dim)">{{ $v->link_clicked_at->timezone(\App\Filament\Pages\SalesEmailOutreach::TZ)->format('M j, g:i a') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="sx-sub">Nobody has opened their link yet.</td></tr>
            @endforelse
            </tbody>
        @endif
        </table>
        @if ($listTotal > $per)
            <div class="eo-pager">
                <span>{{ number_format(($listPage - 1) * $per + 1) }}–{{ number_format(min($listTotal, $listPage * $per)) }} of {{ number_format($listTotal) }}</span>
                <span style="display:flex;gap:6px">
                    <button type="button" class="sx-btn sm" wire:click="listPageTo({{ $listPage - 1 }})" @disabled($listPage <= 1)>‹ Prev</button>
                    <button type="button" class="sx-btn sm" wire:click="listPageTo({{ $listPage + 1 }})" @disabled($listPage >= $pages)>Next ›</button>
                </span>
            </div>
        @endif
    </div>
    @endif
</div>
</x-filament-panels::page>
