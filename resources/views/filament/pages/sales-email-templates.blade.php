{{-- MARKER-EMAIL-OUTREACH: template list + editor with an explicit Save. Same look as Instagram follows. No cards. --}}
@php
    $tpls  = $this->templates();
    $stats = $this->stats();
    $lp    = $this->landingPages();
    $sendUrl = \App\Filament\Pages\SalesEmailOutreach::getUrl();
@endphp

<x-filament-panels::page>
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; font-size:14px; }
  .sx-in { background-color:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; }
  select.sx-in { padding-right:32px; background-repeat:no-repeat; }
  .sx-in option, .sx-in optgroup { background:#26272c; }
  textarea.sx-in { line-height:1.55; resize:vertical; font-family:inherit; }
  .sx-lede { color:var(--sx-faint); font-size:13px; line-height:1.55; margin:0; max-width:84ch; }
  .sx-lede b { color:#fff; font-weight:600; }
  .sx-lede a { color:#a78bfa; }
  .sx-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:6px 12px; font-weight:500; font-size:13px; cursor:pointer; white-space:nowrap; color:inherit; }
  .sx-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .sx-btn.sm { padding:4px 9px; font-size:12.5px; }
  .sx-btn.danger { border-color:rgba(244,124,124,.55); color:#f47c7c; }
  .sx-btn[disabled] { opacity:.55; cursor:default; }
  .et-cols { display:grid; grid-template-columns:300px 1fr; gap:36px; margin-top:18px; }
  .et-cols > section { min-width:0; }
  .et-head { display:flex; justify-content:space-between; align-items:center; padding-bottom:8px; border-bottom:1px solid var(--sx-line-2); font-size:12.5px; color:var(--sx-faint); }
  .et-item { display:block; width:100%; text-align:left; background:none; border:0; border-bottom:1px solid var(--sx-line); padding:11px 0 11px 10px; color:inherit; cursor:pointer; border-left:2px solid transparent; }
  .et-item .nm { color:var(--sx-dim); font-size:13.5px; }
  .et-item .sub { font-size:12px; color:var(--sx-faint); margin-top:2px; }
  .et-item.on { border-left-color:var(--sx-violet); }
  .et-item.on .nm { color:#fff; font-weight:600; }
  .et-item:hover .nm { color:#fff; }
  .et-empty { font-size:12.5px; color:var(--sx-faint); padding:11px 0; }
  .eo-two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .eo-lbl { font-size:12.5px; color:var(--sx-faint); margin:14px 0 4px; }
  .eo-lbl:first-child { margin-top:0; }
  .eo-two .eo-lbl:first-child { margin-top:14px; }
  .eo-full { width:100%; }
  .eo-chips { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:6px; font-size:12px; color:var(--sx-faint); }
  .eo-chip { border:1px solid var(--sx-line-2); border-radius:999px; padding:2px 10px; font-size:12px; color:var(--sx-dim); background:none; cursor:pointer; }
  .eo-chip:hover { border-color:var(--sx-violet); color:#fff; }
  .eo-err { color:#f47c7c; font-size:12px; margin-top:4px; }
  .et-help { margin-top:12px; font-size:12.5px; color:var(--sx-faint); line-height:1.55; max-width:84ch; }
  .et-help b { color:#fff; font-weight:600; }
  .et-confirm { display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:13px; color:#f47c7c; }
  @media (max-width:860px) { .et-cols, .eo-two { grid-template-columns:1fr; gap:10px; } }
</style>

<div class="sx-root"
     x-data="{
        ins(tok) {
            const t = this.$refs.body; if (! t) return;
            t.setRangeText(tok, t.selectionStart, t.selectionEnd, 'end');
            t.dispatchEvent(new Event('input', { bubbles: true }));
            t.focus();
        }
     }">

    <p class="sx-lede"><b>What this does:</b> Saved emails for <a href="{{ $sendUrl }}">Email outreach</a> and the Email button on a Prospects card. <b>[first_name]</b>, <b>[shop]</b> and <b>[city]</b> fill in from the prospect; <b>[link]</b> becomes that shop's own link to the page you pick when sending (the page here is only the starting choice). The unsubscribe link and postal address are added to every email automatically. Editing a template doesn't change emails already sent. <b>Send test to me</b> sends it to you, filled in with a sample shop.</p>

    <div class="et-cols">
        <section>
            <div class="et-head"><span>Templates</span><button type="button" class="sx-btn sm" wire:click="startNew">+ New</button></div>
            @forelse ($tpls as $t)
                @php $s = $stats[$t->id] ?? ['sent' => 0, 'visited' => 0]; @endphp
                <button type="button" class="et-item {{ $editId === (string) $t->id ? 'on' : '' }}" wire:key="t-{{ $t->id }}" wire:click="openTemplate('{{ $t->id }}')">
                    <div class="nm">{{ $t->name }}</div>
                    <div class="sub">Opens: {{ \App\Services\Sales\ProspectOutreach::pageLabel($t->page) }} · sent {{ number_format($s['sent']) }} · {{ number_format($s['visited']) }} visited</div>
                </button>
            @empty
                <div class="et-empty">No templates yet. Write the first one on the right.</div>
            @endforelse
            @if ($editId === 'new' && $tpls->isNotEmpty())
                <div class="et-item on"><div class="nm">New template</div><div class="sub">Not saved yet</div></div>
            @endif
            {{-- MARKER-OUTREACH-TEST-SIG --}}
            @php $mySig = \App\Services\Sales\ProspectOutreach::signature(); @endphp
            <div class="et-head" style="margin-top:22px"><span>Your signature</span></div>
            <button type="button" class="et-item {{ $editId === 'signature' ? 'on' : '' }}" wire:click="openSignature">
                <div class="nm">Signature</div>
                <div class="sub">{{ $mySig['enabled'] ? 'On · added to every email you send' : 'Off' }}</div>
            </button>
        </section>

        <section>
            @if ($editId === 'signature')
                @php
                    $logoUrl = \App\Services\Sales\ProspectOutreach::signatureLogoUrl(array_merge($mySig, $sig));
                    $svg = \App\Services\Sales\ProspectOutreach::logoIsSvg($logoUrl);
                @endphp
                <label class="eo-chips" style="font-size:13px;color:inherit;margin:0"><input type="checkbox" wire:model.live="sig.enabled" style="accent-color:#8b5cf6"> Add my signature to every prospect email I send</label>
                <div class="eo-two">
                    <div><div class="eo-lbl">Name</div><input type="text" class="sx-in eo-full" wire:model.live.debounce.400ms="sig.name"></div>
                    <div><div class="eo-lbl">Title</div><input type="text" class="sx-in eo-full" wire:model.live.debounce.400ms="sig.title" placeholder="Founder, Intake"></div>
                    <div><div class="eo-lbl">Phone</div><input type="text" class="sx-in eo-full" wire:model.live.debounce.400ms="sig.phone"></div>
                    <div><div class="eo-lbl">Extra line <span style="color:var(--sx-faint)">(optional)</span></div><input type="text" class="sx-in eo-full" wire:model.live.debounce.400ms="sig.line" placeholder="20 years in shops. Built the software I wanted."></div>
                </div>
                <div class="eo-two">
                    @foreach (\App\Services\Sales\ProspectOutreach::SOCIALS as $k => $label)
                        <div><div class="eo-lbl">{{ $label }} link</div><input type="text" class="sx-in eo-full" wire:model.live.debounce.400ms="sig.{{ $k }}" placeholder="{{ $k === 'website' ? 'intake.works' : $k . '.com/…' }}"></div>
                    @endforeach
                </div>
                <div class="eo-lbl">Logo</div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <select class="sx-in" wire:model.live="sig.logo">
                        <option value="brand">Brand logo for email</option>
                        <option value="upload">My own logo</option>
                        <option value="none">No logo</option>
                    </select>
                    @if (($sig['logo'] ?? 'brand') === 'upload')
                        <input type="file" accept="image/png,image/jpeg" wire:model="logoUpload" class="sx-in" style="max-width:280px">
                    @endif
                </div>
                @error('logoUpload')<div class="eo-err">{{ $message }}</div>@enderror
                @if (($sig['logo'] ?? 'brand') === 'brand' && $svg)
                    <div class="et-help" style="color:#fbbf24">The Brand logo for email is an SVG, which Gmail and Outlook don't show. Upload a PNG in Brand › Logo for email, or choose My own logo.</div>
                @endif

                <div class="eo-lbl" style="margin-top:18px">Preview</div>
                <div style="border:1px solid var(--sx-line-2);border-radius:8px;background:#fff;color:#111;padding:6px 18px 18px">
                    @if ($logoUpload)
                        <div style="font-size:12px;color:#777;margin-top:10px">New logo (saved when you press Save):</div>
                        <img src="{{ $logoUpload->temporaryUrl() }}" alt="" style="width:120px;height:auto;margin-top:6px">
                    @endif
                    {!! $this->signaturePreview() !!}
                </div>
                <div class="et-help">Shown under the message and above the unsubscribe line. Only your emails use your signature; anyone else sending from master admin sets their own.</div>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:16px">
                    <button type="button" class="sx-btn p" wire:click="saveSignature" wire:loading.attr="disabled">Save signature</button>
                </div>
            @else
            <div class="eo-lbl">Name <span style="color:var(--sx-faint)">(only you see this)</span></div>
            <input type="text" class="sx-in eo-full" wire:model="name" placeholder="e.g. Intro: one login for the whole shop">
            @error('name')<div class="eo-err">{{ $message }}</div>@enderror

            <div class="eo-two">
                <div>
                    <div class="eo-lbl">Their link opens</div>
                    <select class="sx-in eo-full" wire:model="landing">
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
                <div>
                    <div class="eo-lbl">Subject</div>
                    <input type="text" class="sx-in eo-full" wire:model="subject" placeholder="Quick question about [shop]">
                    @error('subject')<div class="eo-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="eo-lbl">Message</div>
            <textarea class="sx-in eo-full" rows="14" x-ref="body" wire:model="body"></textarea>
            @error('body')<div class="eo-err">{{ $message }}</div>@enderror
            <div class="eo-chips">Insert:
                @foreach (\App\Services\Sales\ProspectOutreach::TOKENS as $tok)
                    <button type="button" class="eo-chip" x-on:click="ins('{{ $tok }}')">{{ $tok }}</button>
                @endforeach
            </div>
            <div class="et-help">With no owner name on file, <b>[first_name]</b> is left out, so "Hi [first_name]," reads "Hi,". Without <b>[link]</b> the email carries no link and visits can't be traced to the shop.</div>

            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:16px">
                <button type="button" class="sx-btn p" wire:click="saveTemplate" wire:loading.attr="disabled">Save template</button>
                <button type="button" class="sx-btn" wire:click="sendTest" wire:loading.attr="disabled" title="Sends this template, filled in with a sample shop, only to you">Send test to me</button>
                @if ($editId && $editId !== 'new')
                    @if (! $confirmDelete)
                        <button type="button" class="sx-btn" wire:click="$set('confirmDelete', true)">Delete</button>
                    @else
                        <span class="et-confirm">Delete this template?
                            <button type="button" class="sx-btn sm danger" wire:click="deleteTemplate">Yes, delete</button>
                            <button type="button" class="sx-btn sm" wire:click="$set('confirmDelete', false)">Cancel</button>
                        </span>
                    @endif
                @endif
            </div>
            @endif
        </section>
    </div>
</div>
</x-filament-panels::page>
