<x-filament-panels::page>
<style>
  .pc{--pc-line:var(--ia-border,rgba(127,127,127,.22));--pc-accent:#8b7cf6;--pc-warn:#f0c46a}
  .pc-sender{border:1px solid var(--pc-line);border-radius:12px;padding:12px 16px;display:flex;gap:26px;
    align-items:center;flex-wrap:wrap;font-size:13px;margin-bottom:18px}
  .pc-sender .lab{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;opacity:.5;font-weight:700;margin-right:8px}
  .pc-sender code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px}
  .pc-sender .sp{margin-left:auto}
  .pc-tabs{display:flex;gap:26px;border-bottom:1px solid var(--pc-line);margin-bottom:16px;font-size:14px}
  .pc-tabs span{padding:10px 0 12px;opacity:.55}
  .pc-tabs .on{opacity:1;border-bottom:2px solid var(--pc-accent);margin-bottom:-1px;font-weight:600}
  .pc-grp{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;opacity:.45;font-weight:700;
    padding:14px 0 8px}
  .pc-list{border:1px solid var(--pc-line);border-radius:12px;overflow:hidden}
  .pc-row{display:grid;grid-template-columns:1fr 110px 1fr 70px;gap:14px;align-items:center;
    padding:13px 16px;border-bottom:1px solid rgba(127,127,127,.12)}
  .pc-row:last-child{border-bottom:0}
  .pc-row .t{font-size:14.5px}
  .pc-row .d{font-size:11.5px;opacity:.5;margin-top:2px}
  .pc-row .fires{font-size:12.5px;opacity:.65}
  .pc-pill{font-size:10.5px;padding:2px 8px;border-radius:99px;border:1px solid var(--pc-line);opacity:.75}
  .pc-pill--custom{background:rgba(139,124,246,.14);border-color:rgba(139,124,246,.4);color:#cfc7ff;opacity:1}
  .pc-edit{background:none;border:0;color:var(--pc-accent);font:inherit;font-size:13px;cursor:pointer;text-align:right}
  .pc-acts-row{display:flex;gap:12px;justify-content:flex-end;align-items:center;font-size:13px;white-space:nowrap}
  .pc-del{background:none;border:0;color:var(--pc-warn, #f0c46a);font:inherit;font-size:13px;cursor:pointer;opacity:.8}
  .pc-del:hover{opacity:1}
  .pc-rule{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--pc-line);border-radius:99px;padding:3px 6px 3px 10px;font-size:12px;margin:0 6px 6px 0}
  .pc-rule button{background:none;border:0;color:inherit;opacity:.55;cursor:pointer;font:inherit;padding:0 4px}
  .pc-rule button:hover{opacity:1}
  /* MARKER-CAMPAIGN-REPORT */
  .rp-head{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap;margin-bottom:4px}
  .rp-head h3{font-size:17px;font-weight:700;margin:0}
  .rp-head .sub{font-size:12.5px;opacity:.55}
  .rp-head .sp{margin-left:auto;display:flex;gap:14px;font-size:12.5px}
  .rp-prog{margin:14px 0 4px}
  .rp-bar{display:flex;height:8px;border-radius:5px;overflow:hidden;background:rgba(127,127,127,.15)}
  .rp-bar i{display:block;height:100%}
  .rp-prog .l{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;opacity:.7;margin-top:6px}
  .rp-prog .l b{opacity:1}
  .rp-k{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));border:1px solid var(--pc-line);border-radius:12px;margin:16px 0}
  @media(max-width:1000px){.rp-k{grid-template-columns:repeat(3,minmax(0,1fr))}}
  .rp-k button{background:none;border:0;border-right:1px solid var(--pc-line);color:inherit;font:inherit;text-align:left;padding:12px 14px;cursor:pointer}
  .rp-k button:last-child{border-right:0}
  .rp-k button.on{background:rgba(139,124,246,.1);box-shadow:inset 0 -2px 0 var(--pc-accent)}
  .rp-k .k{display:block;font-size:11.5px;opacity:.55}
  .rp-k .v{display:block;font-size:22px;font-weight:700;letter-spacing:-.02em;line-height:1.2}
  .rp-k .d{display:block;font-size:11.5px;opacity:.6}
  .rp-k .bad .v{color:#f08a8a}
  .rp-plot{position:relative;height:120px}
  .rp-plot svg{position:absolute;inset:0;width:100%;height:100%}
  .rp-axis{display:flex;justify-content:space-between;font-size:11px;opacity:.45;margin-top:4px}
  .rp-key{display:flex;gap:14px;font-size:11.5px;opacity:.7;margin-top:6px}
  .rp-key i{display:inline-block;width:10px;height:3px;border-radius:2px;vertical-align:middle;margin-right:5px}
  .rp-side{display:grid;grid-template-columns:minmax(0,2fr) minmax(220px,1fr);gap:22px;align-items:start}
  @media(max-width:1000px){.rp-side{grid-template-columns:1fr}}
  .rp-mini{font-size:12.5px;line-height:1.9}
  .rp-mini .r{display:flex;gap:8px}.rp-mini .r span:first-child{flex:1;opacity:.7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rp-filters{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:18px 0 10px}
  .rp-filters button{border:1px solid var(--pc-line);background:none;color:inherit;font:inherit;font-size:12.5px;border-radius:99px;padding:5px 11px;cursor:pointer;opacity:.75}
  .rp-filters button.on{border-color:var(--pc-accent);background:rgba(139,124,246,.12);opacity:1;font-weight:600}
  .rp-filters input{margin-left:auto;background:transparent;border:1px solid var(--pc-line);border-radius:8px;color:inherit;font:inherit;font-size:13px;padding:6px 10px;width:220px}
  .rp-tbl{width:100%;border-collapse:collapse;font-size:13px}
  .rp-tbl th{text-align:left;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;opacity:.45;font-weight:700;padding:8px 10px;border-bottom:1px solid var(--pc-line)}
  .rp-tbl td{padding:8px 10px;border-bottom:1px solid var(--pc-line);vertical-align:top}
  .rp-tbl .em{font-size:11.5px;opacity:.55}
  .rp-tbl a{color:inherit}
  .rp-ev{display:inline-block;font-size:11px;border-radius:99px;padding:1px 8px;margin:0 4px 3px 0;border:1px solid var(--pc-line);white-space:nowrap}
  .rp-ev.good{border-color:rgba(127,217,143,.45);color:#7fd98f}
  .rp-ev.bad{border-color:rgba(240,138,138,.5);color:#f08a8a}
  .rp-ev.warn{border-color:rgba(240,196,106,.5);color:#f0c46a}
  .rp-pager{display:flex;align-items:center;gap:10px;justify-content:flex-end;font-size:12.5px;margin-top:10px}
  .pc-progline{height:3px;border-radius:2px;background:rgba(127,127,127,.18);margin-top:5px;overflow:hidden;max-width:220px}
  .pc-progline i{display:block;height:100%;background:var(--pc-accent)}
  .pc-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
  @media(max-width:1000px){.pc-cols{grid-template-columns:1fr}}
  .pc-card{border:1px solid var(--pc-line);border-radius:12px;padding:16px 18px}
  .pc-card h3{font-size:15px;font-weight:700;margin:0 0 3px}
  .pc-card .sub{font-size:12px;opacity:.55;margin-bottom:14px}
  .pc-f{margin-bottom:12px}
  .pc-f label{display:block;font-size:11.5px;opacity:.65;margin-bottom:5px}
  .pc-f input,.pc-f textarea{width:100%;background:transparent;border:1px solid var(--pc-line);border-radius:8px;
    color:inherit;font:inherit;font-size:13.5px;padding:8px 10px}
  .pc-f textarea{min-height:230px;resize:vertical;line-height:1.6}
  .pc-tok{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
  .pc-tok code{font-size:11.5px;border:1px solid var(--pc-line);border-radius:99px;padding:2px 8px;opacity:.8}
  .pc-back{background:none;border:0;padding:0;margin:0 0 10px;color:inherit;opacity:.6;font:inherit;font-size:12.5px;cursor:pointer}
  .pc-back:hover{opacity:1}
  .pc-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px}
  .pc-btn{border:1px solid var(--pc-line);background:none;color:inherit;font:inherit;font-size:13px;
    padding:7px 13px;border-radius:9px;cursor:pointer}
  .pc-btn--pri{background:var(--pc-accent);border-color:var(--pc-accent);color:#14121f;font-weight:600}
  .pc-btn--warn{color:var(--pc-warn);border-color:rgba(240,196,106,.4)}
  .pc-btn:disabled{opacity:.4;cursor:not-allowed}
  .pl-file{position:relative;overflow:hidden;display:inline-block}
  .pl-file input{position:absolute;inset:0;opacity:0;cursor:pointer}
  .pl-seg{display:inline-flex;border:1px solid var(--pc-line);border-radius:8px;padding:2px}
  .pl-seg button{background:none;border:0;color:inherit;opacity:.6;padding:5px 11px;border-radius:6px;font:inherit;font-size:12.5px;cursor:pointer}
  .pl-seg button.on{opacity:1;background:rgba(255,255,255,.08);font-weight:600}
  /* MARKER-FEATURE-BLOCKS: the block list reads as a list of sections. Each
     block is a card with a numbered header (type, kind chip, controls) and a
     body of labelled fields; the add palette is grouped by kind. */
  .pl-list{counter-reset:plb;margin-top:6px}
  .pl-blk{border:1px solid var(--pc-line);border-radius:12px;margin-bottom:10px;background:rgba(255,255,255,.015);counter-increment:plb}
  .pl-blk-h{display:flex;align-items:center;gap:10px;padding:10px 12px;font-size:13.5px;cursor:pointer;user-select:none}
  .pl-blk-h::before{content:counter(plb);min-width:22px;height:22px;border-radius:6px;display:grid;place-items:center;font-size:11px;font-weight:700;background:rgba(255,255,255,.06);opacity:.8}
  .pl-blk-h b{font-weight:650}
  .pl-kind{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:99px;border:1px solid var(--pc-line);opacity:.7}
  .pl-kind--feat{border-color:rgba(190,242,100,.45);color:#BEF264;opacity:1}
  .pl-blk-h .pl-ctl{margin-left:auto;display:flex;gap:2px}
  .pl-blk-h .pl-ctl button{background:none;border:0;color:inherit;opacity:.55;cursor:pointer;font-size:13px;width:26px;height:26px;border-radius:6px}
  .pl-blk-h .pl-ctl button:hover{opacity:1;background:rgba(255,255,255,.06)}
  .pl-blk-b{padding:4px 14px 14px;border-top:1px solid var(--pc-line)}
  .pl-l{display:block;font-size:11.5px;font-weight:600;opacity:.6;margin:10px 0 5px}
  .pl-blk textarea,.pl-blk input[type=text]{width:100%;background:transparent;border:1px solid var(--pc-line);border-radius:8px;color:inherit;font:inherit;font-size:13.5px;padding:8px 10px}
  .pl-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .pl-row3{display:grid;grid-template-columns:1.4fr 1fr 1.4fr;gap:8px}
  .pl-grid{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:6px}
  .pl-grid .pl-h{font-size:12px;opacity:.75}
  .pl-tilebox{border:1px solid var(--pc-line);border-radius:10px;padding:8px 10px 10px;border-left-width:4px}
  .pl-shot{position:relative;display:inline-block;max-width:100%;cursor:crosshair;margin-top:8px}
  .pl-shot img{display:block;max-width:100%;border-radius:8px;border:1px solid var(--pc-line)}
  .pl-pin{position:absolute;width:24px;height:24px;margin:-12px 0 0 -12px;border-radius:50%;background:#BEF264;color:#10140a;font-size:12px;font-weight:800;display:grid;place-items:center;box-shadow:0 0 0 4px rgba(190,242,100,.3);pointer-events:none}
  .pl-mk-shot{display:flex;align-items:center;gap:10px;margin:-2px 0 10px 30px;flex-wrap:wrap}
  .pl-mk-shot img{width:120px;height:auto;border-radius:6px;border:1px solid var(--pc-line)}
  .pl-mk{display:grid;grid-template-columns:26px 1fr 1.6fr 26px;gap:8px;align-items:center;margin-top:8px}
  .pl-mk i{width:22px;height:22px;border-radius:50%;background:#BEF264;color:#10140a;font-style:normal;font-size:11px;font-weight:800;display:grid;place-items:center}
  .pl-mk button{background:none;border:0;color:inherit;opacity:.55;cursor:pointer}
  .pl-add{border:1px dashed var(--pc-line);border-radius:12px;padding:12px 14px;margin:4px 0 16px}
  .pl-add-g{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px}
  .pl-add-g:last-child{margin-bottom:0}
  .pl-add-g > span{font-size:11.5px;font-weight:600;opacity:.6;width:64px}
  .pl-add .pc-btn{font-size:12.5px;padding:5px 10px}
  .pl-add .pl-add-feat .pc-btn{border-color:rgba(190,242,100,.35)}

  /* MARKER-COMPOSER-LAYOUT: preview stays in view; insert between blocks;
     folded blocks show their content in one line. */
  .pc-cols > .pc-sticky{position:sticky;top:76px;max-height:calc(100vh - 92px);overflow:auto}
  @media(max-width:1000px){.pc-cols > .pc-sticky{position:static;max-height:none}}
  .pl-tools{display:flex;justify-content:space-between;align-items:center;margin:2px 0 8px;font-size:12px}
  .pl-tools button{background:none;border:0;color:inherit;opacity:.65;cursor:pointer;font:inherit;padding:2px 6px;border-radius:6px}
  .pl-tools button:hover{opacity:1;background:rgba(127,127,127,.12)}
  .pl-sum{font-size:12.5px;opacity:.55;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;flex:1}
  .pl-ins{position:relative;height:14px;margin:-2px 0 6px}
  .pl-ins > button{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:24px;height:24px;border-radius:50%;
    border:1px solid var(--pc-line);background:var(--pc-bg2,#fff);color:inherit;font-size:15px;line-height:1;cursor:pointer;opacity:.35;transition:opacity .12s}
  .pl-ins:hover > button,.pl-ins.on > button{opacity:1;border-color:var(--pc-accent)}
  .pl-ins::before{content:'';position:absolute;left:0;right:0;top:50%;border-top:1px dashed transparent}
  .pl-ins:hover::before,.pl-ins.on::before{border-top-color:var(--pc-accent)}
  .pl-pick{position:absolute;left:50%;top:22px;transform:translateX(-50%);z-index:20;width:min(560px,96%);background:var(--pc-bg2,#fff);
    border:1px solid var(--pc-line);border-radius:12px;padding:12px 14px;box-shadow:0 18px 40px rgba(0,0,0,.35)}
  /* dark: lift the working surfaces off the page so it isn't black on black */
  .dark .pc{--pc-line:rgba(255,255,255,.12);--pc-bg2:#1c1c1f}
  .dark .pc-card{background:#141416}
  .dark .pl-blk{background:#1c1c1f;border-color:rgba(255,255,255,.1)}
  .dark .pl-blk-h::before{background:rgba(255,255,255,.1)}
  .dark .pl-blk-b{border-top-color:rgba(255,255,255,.08)}
  .dark .pc-f input,.dark .pc-f textarea,.dark .pc-f select,.dark .pl-blk textarea,.dark .pl-blk input[type=text]{background:#26262a;border-color:rgba(255,255,255,.1)}
  .dark .pl-seg{background:#1c1c1f}
  .dark .pl-seg button.on{background:rgba(255,255,255,.12)}
  .dark .pl-add{background:#18181b;border-color:rgba(255,255,255,.14)}
  .dark .pl-tilebox{background:#232327}
  .dark .pc-btn{background:#232327}
  .dark .pc-btn--pri{background:var(--pc-accent)}
  .pc-note{font-size:12px;opacity:.6;line-height:1.6;margin-top:10px}
  .pc-frame{border:1px solid var(--pc-line);border-radius:10px;overflow:hidden;background:#fff}
  .pc-frame iframe{width:100%;height:520px;border:0;display:block}
  .pc-legend{border:1px solid var(--pc-line);border-radius:12px;padding:11px 14px;font-size:12.5px;
    opacity:.85;line-height:1.6;margin-bottom:16px}
  .pc-legend b{opacity:1}
</style>

<div class="pc">
  <div class="pc-sender">
    <span><span class="lab">Sending as</span><code>{{ $fromLine }}</code></span>
    <span><span class="lab">Replies</span><code>{{ $replyLine }}</code></span>
    <a class="sp" href="{{ url('/admin/platform-email') }}" style="font-size:13px;color:var(--pc-accent);text-decoration:none">Edit sender details →</a>
  </div>

  @unless($inboundOk)
    <div class="pc-legend" style="border-color:rgba(240,196,106,.35)">
      <b style="color:#f0c46a">Replies have nowhere to land.</b> POSTMARK_INBOUND_ADDRESS isn't configured, so
      Reply-To falls back to the from address and an answer goes to that mailbox instead of the inbox.
    </div>
  @endunless

  <div class="pc-tabs">
    <span class="{{ $tab === 'messages' ? 'on' : '' }}" style="cursor:pointer" wire:click="setTab('messages')">Messages</span>
    <span class="{{ $tab === 'campaigns' ? 'on' : '' }}" style="cursor:pointer" wire:click="setTab('campaigns')">Campaigns</span>
    <span class="{{ $tab === 'activity' ? 'on' : '' }}" style="cursor:pointer" wire:click="setTab('activity')">Activity</span>
    <span class="{{ $tab === 'suppressions' ? 'on' : '' }}" style="cursor:pointer" wire:click="setTab('suppressions')">Suppressions</span>
  </div>

  @if($tab === 'messages')
  <div class="pc-legend">
    <b>Every email Intake sends about itself is listed here</b> — not a shop's mail to its customers, which each
    shop controls on its own Communication page. Three of them edit on this page; billing notices and investor
    messages have their own editors and are linked; the rest are fixed, each saying why.
  </div>

  @if($editingKey && $meta)
    <div class="pc-cols">
      <div class="pc-card">
        <h3>{{ $meta['label'] }}</h3>
        <div class="sub">{{ $meta['fires'] }}</div>

        <div class="pc-f">
          <label>Subject</label>
          <input type="text" wire:model="subject" placeholder="{{ $meta['subject'] }}">
        </div>

        <div class="pc-f">
          <label>Body</label>
          <textarea wire:model="body" placeholder="Write the email. Blank lines make paragraphs."></textarea>
          <div class="pc-tok">
            @foreach($meta['tokens'] as $tok)<code>&#123;&#123;{{ $tok }}&#125;&#125;</code>@endforeach
          </div>
        </div>

        <div class="pc-acts">
          <button type="button" class="pc-btn pc-btn--pri" wire:click="save">Save</button>
          <button type="button" class="pc-btn" wire:click="cancel">Close</button>
          <button type="button" class="pc-btn pc-btn--warn" wire:click="revert">Revert to built-in</button>
        </div>

        <div class="pc-f" style="margin-top:16px">
          <label>Send a test</label>
          <div style="display:flex;gap:8px">
            <input type="text" wire:model="testTo" placeholder="you@intake.works">
            <button type="button" class="pc-btn" wire:click="sendTest">Send</button>
          </div>
          <div class="pc-note">The test uses sample values, so tokens read like a real email rather than braces.</div>
        </div>
      </div>

      <div class="pc-card">
        <h3>Preview</h3>
        <div class="sub">Your text, with sample values filled in</div>
        <div class="pc-frame">
          <iframe srcdoc="{{ $preview }}" title="Email preview"></iframe>
        </div>
        <div class="pc-note">
          A customised email renders in plain Intake chrome, not the original layout — that's deliberate, so a
          paste can't break the HTML. Revert restores the shipped design exactly.
        </div>
      </div>
    </div>
  @else
    @foreach($groups as $group => $rows)
      <div class="pc-grp">{{ $group }}</div>
      <div class="pc-list">
        @foreach($rows as $key => $row)
          <div class="pc-row">
            <div>
              <div class="t">{{ $row['label'] }}</div>
              <div class="d">{{ $row['description'] }}</div>
            </div>
            <div>
              @if($row['override'])
                <span class="pc-pill pc-pill--custom">Customised</span>
              @else
                <span class="pc-pill">Built-in</span>
              @endif
            </div>
            <div class="fires">{{ $row['fires'] }}</div>
            <button type="button" class="pc-edit" wire:click="edit('{{ $key }}')">Edit</button>
          </div>
        @endforeach
      </div>
    @endforeach

    {{-- the rest of what the platform sends. --}}
    @foreach($others as $group => $rows)
      <div class="pc-grp">{{ $group }}</div>
      <div class="pc-list">
        @foreach($rows as $row)
          <div class="pc-row" style="grid-template-columns:1fr 130px 1fr 110px">
            <div>
              <div class="t">{{ $row['label'] }}</div>
              <div class="d">{{ $row['note'] }}</div>
            </div>
            <div>
              @if($row['edit'] === 'elsewhere')
                <span class="pc-pill">Edited elsewhere</span>
              @else
                <span class="pc-pill">Fixed</span>
              @endif
            </div>
            <div class="fires">{{ $row['fires'] }}</div>
            <div style="text-align:right">
              @if($row['edit'] === 'elsewhere')
                <a href="{{ url($row['where']) }}" style="color:var(--pc-accent);text-decoration:none;font-size:13px">Open →</a>
              @else
                <span style="opacity:.35;font-size:12.5px">—</span>
              @endif
            </div>
          </div>
        @endforeach
      </div>
    @endforeach
  @endif
  @endif

  {{-- ======================================================= CAMPAIGNS --}}
  @if($tab === 'campaigns')
    <div class="pc-legend">
      <b>Campaigns go to Intake's own people</b> — tenants, prospects, people who wrote in, reps. A shop's
      customers are never an audience here; that list belongs to the shop.
      @unless($streamOk)
        <br><b style="color:#f0c46a">No platform broadcast stream is set, so nothing can send yet.</b>
      @endunless
    </div>

    @if($campaign && $report && $reportData)
      {{-- MARKER-CAMPAIGN-REPORT: takes the editor's place; nothing here changes the campaign --}}
      @php
        $R = $reportData['sum']; $L = $reportData['list'];
        $fmtPct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
        $eta = $R['eta_minutes'];
        $etaTxt = $eta === null ? null : ($eta < 90 ? '~' . $eta . ' min left' : '~' . round($eta / 60, 1) . ' h left');
        $filterCount = ['all' => $R['total'], 'opened' => $R['opened'], 'clicked' => $R['clicked'], 'replied' => $R['replied'],
          'unopened' => max(0, $R['delivered'] - $R['opened']), 'bounced' => $R['bounced'], 'complained' => $R['complained'],
          'unsubscribed' => $R['unsubscribed'], 'pending' => $R['pending'], 'skipped' => $R['skipped'], 'failed' => $R['failed']];
        $tl = $R['timeline'];
      @endphp
      <div class="pc-card" @if(in_array($campaign->status, ['sending', 'scheduled'], true)) wire:poll.20s @endif>
        <div style="display:flex;gap:16px">
          <button type="button" class="pc-back" wire:click="closeReport">&larr; Back to campaign</button>
          <button type="button" class="pc-back" wire:click="closeCampaign">All campaigns</button>
        </div>
        <div class="rp-head">
          <h3>{{ $campaign->name }}</h3>
          <span class="sub">{{ $campaign->subject }}</span>
          <span class="sp"><span class="pc-pill {{ $campaign->status === 'sent' ? 'pc-pill--custom' : '' }}">{{ ucfirst($campaign->status) }}</span></span>
        </div>
        <div class="sub" style="font-size:12px;opacity:.55">
          {{ optional($campaign->audience)->name ?: 'No audience' }}
          @if($R['first_sent']) · first sent {{ alocal(\Carbon\Carbon::parse($R['first_sent'], 'UTC'), 'D M j, g:i a') }}@endif
          @if($campaign->status === 'sent' && $campaign->sent_at) · finished {{ alocal($campaign->sent_at, 'D M j, g:i a') }}@endif
          @if($campaign->status === 'scheduled' && $campaign->scheduled_at) · starts {{ alocal($campaign->scheduled_at, 'D M j, g:i a') }}@endif
        </div>

        {{-- sending progress --}}
        @if($R['total'] > 0)
          <div class="rp-prog">
            <div class="rp-bar">
              <i style="width:{{ $R['total'] ? $R['sent'] / $R['total'] * 100 : 0 }}%;background:var(--pc-accent)"></i>
              <i style="width:{{ $R['total'] ? $R['skipped'] / $R['total'] * 100 : 0 }}%;background:rgba(127,127,127,.45)"></i>
              <i style="width:{{ $R['total'] ? $R['failed'] / $R['total'] * 100 : 0 }}%;background:#f08a8a"></i>
            </div>
            <div class="l">
              <span><b>{{ number_format($R['sent']) }}</b> sent</span>
              <span><b>{{ number_format($R['pending']) }}</b> waiting</span>
              <span><b>{{ number_format($R['skipped']) }}</b> skipped</span>
              <span><b>{{ number_format($R['failed']) }}</b> failed</span>
              <span>of <b>{{ number_format($R['total']) }}</b> · {{ $R['progress'] }}% done</span>
              @if($R['pending'] > 0)
                @if($campaign->deliveryKey() === 'personal')
                  <span>one at a time: each waits for its Send button</span>
                @else
                <span>{{ number_format($R['last_hour']) }} sent in the last hour{{ $etaTxt ? ' · ' . $etaTxt : ($R['last_hour'] === 0 ? ' · paused or between paced sends' : '') }}</span>
                @endif
              @endif
            </div>
          </div>
        @elseif($campaign->status === 'scheduled')
          <div class="pc-note">The recipient list is built when it starts, so anyone who unsubscribes before then is left out. Progress shows here from that moment.</div>
        @else
          <div class="pc-note">Nothing has been sent yet.</div>
        @endif

        {{-- headline rates; each one filters the list below --}}
        <div class="rp-k">
          <button type="button" class="{{ $rFilter === 'all' ? 'on' : '' }}" wire:click="setReportFilter('all')"><span class="k">Delivered</span><span class="v">{{ number_format($R['delivered']) }}</span><span class="d">of {{ number_format($R['sent']) }} sent</span></button>
          <button type="button" class="{{ $rFilter === 'opened' ? 'on' : '' }}" wire:click="setReportFilter('opened')"><span class="k">Opened</span><span class="v">{{ $fmtPct($R['open_rate']) }}</span><span class="d">{{ number_format($R['opened']) }} {{ $R['opened'] === 1 ? 'person' : 'people' }}</span></button>
          <button type="button" class="{{ $rFilter === 'clicked' ? 'on' : '' }}" wire:click="setReportFilter('clicked')"><span class="k">Clicked</span><span class="v">{{ $fmtPct($R['click_rate']) }}</span><span class="d">{{ number_format($R['clicked']) }} {{ $R['clicked'] === 1 ? 'person' : 'people' }}</span></button>
          <button type="button" class="{{ $rFilter === 'replied' ? 'on' : '' }}" wire:click="setReportFilter('replied')"><span class="k">Replied</span><span class="v">{{ $fmtPct($R['reply_rate']) }}</span><span class="d">{{ number_format($R['replied']) }} {{ $R['replied'] === 1 ? 'person' : 'people' }}</span></button>
          <button type="button" class="{{ $rFilter === 'bounced' ? 'on' : '' }} {{ ($R['bounce_rate'] ?? 0) >= 5 ? 'bad' : '' }}" wire:click="setReportFilter('bounced')"><span class="k">Bounced</span><span class="v">{{ $fmtPct($R['bounce_rate']) }}</span><span class="d">{{ number_format($R['bounced']) }} {{ $R['bounced'] === 1 ? 'address' : 'addresses' }}</span></button>
          <button type="button" class="{{ $rFilter === 'unsubscribed' ? 'on' : '' }} {{ $R['complained'] > 0 ? 'bad' : '' }}" wire:click="setReportFilter('unsubscribed')"><span class="k">Unsubscribed</span><span class="v">{{ $fmtPct($R['unsub_rate']) }}</span><span class="d">{{ number_format($R['unsubscribed']) }} {{ $R['unsubscribed'] === 1 ? 'person' : 'people' }} · {{ $R['complained'] }} marked spam</span></button>
        </div>

        <div class="rp-side">
          <div>
            <div class="pc-grp" style="padding-top:0">Over time · by {{ $tl['unit'] }}</div>
            @if(count($tl['labels']) > 1)
              @php
                $W = 640; $H = 120; $P = 6; $mx = max(1, max($tl['sent'] ?: [1]));
                $pathOf = function ($arr) use ($W, $H, $P, $mx) { $n = count($arr); $o = [];
                  foreach ($arr as $i => $v) { $o[] = ($i ? 'L' : 'M') . round($n > 1 ? $i / ($n - 1) * $W : $W, 1) . ' ' . round($H - $P - $v / $mx * ($H - 2 * $P), 1); }
                  return implode(' ', $o); };
              @endphp
              <div class="rp-plot">
                <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none">
                  <path d="{{ $pathOf($tl['sent']) }}" fill="none" stroke="rgba(127,127,127,.6)" stroke-width="2" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
                  <path d="{{ $pathOf($tl['opened']) }}" fill="none" stroke="#8b7cf6" stroke-width="2" vector-effect="non-scaling-stroke"/>
                  <path d="{{ $pathOf($tl['clicked']) }}" fill="none" stroke="#7fd98f" stroke-width="2" vector-effect="non-scaling-stroke"/>
                </svg>
              </div>
              <div class="rp-axis"><span>{{ $tl['labels'][0] }}</span><span>{{ $tl['labels'][intdiv(count($tl['labels']), 2)] }}</span><span>{{ end($tl['labels']) }}</span></div>
              <div class="rp-key"><span><i style="background:rgba(127,127,127,.6)"></i>Sent</span><span><i style="background:#8b7cf6"></i>Opened</span><span><i style="background:#7fd98f"></i>Clicked</span><span style="opacity:.6">running totals</span></div>
            @else
              <div class="pc-note" style="margin-top:0">The line starts once sending has run for more than an hour.</div>
            @endif
          </div>
          <div class="rp-mini">
            <div class="pc-grp" style="padding-top:0">Why some were skipped</div>
            @forelse($R['skip_reasons'] as $why => $n)
              <div class="r"><span title="{{ $why }}">{{ $why }}</span><span>{{ number_format($n) }}</span></div>
            @empty
              <div style="opacity:.5">None skipped.</div>
            @endforelse
            @if($R['bounce_types'])
              <div class="pc-grp">Bounce types</div>
              @foreach($R['bounce_types'] as $bt => $n)
                <div class="r"><span>{{ $bt }}</span><span>{{ number_format($n) }}</span></div>
              @endforeach
            @endif
          </div>
        </div>

        @php $canPersonal = optional($campaign->audience)->source !== 'prospects'; @endphp
        @if($canPersonal)
          {{-- MARKER-PERSONAL-DELIVERY --}}
          <div class="pc-note" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px">
            <span>Send or Send again goes to that one person, personally, from {{ \App\Models\PlatformSettings::outreachFrom()[1] }} on the receipts stream. Nothing else goes out.</span>
            @if($campaign->deliveryKey() === 'personal')
              <button type="button" class="pc-edit" wire:click="refreshPersonalList">Add anyone new in the audience</button>
            @endif
          </div>
        @endif
        <div class="rp-filters">
          @foreach($reportFilters as $fk => $fl)
            @if($fk === 'all' || ($filterCount[$fk] ?? 0) > 0 || $rFilter === $fk)
              <button type="button" class="{{ $rFilter === $fk ? 'on' : '' }}" wire:click="setReportFilter('{{ $fk }}')">{{ $fl }} {{ number_format($filterCount[$fk] ?? 0) }}</button>
            @endif
          @endforeach
          <input type="search" wire:model.live.debounce.400ms="rSearch" placeholder="Search name or email">
        </div>

        <table class="rp-tbl">
          <thead><tr><th>Recipient</th><th>Sent</th><th>What happened</th><th></th></tr></thead>
          <tbody>
            @forelse($L['rows'] as $r)
              <tr>
                <td>
                  @if($r->source_type === 'prospects' && $r->source_id)
                    <a href="{{ \App\Filament\Resources\SalesProspectResource::getUrl('edit', ['record' => $r->source_id]) }}" target="_blank">{{ $r->name ?: $r->email }}</a>
                  @else
                    {{ $r->name ?: $r->email }}
                  @endif
                  @if($r->name)<div class="em">{{ $r->email }}</div>@endif
                </td>
                <td style="white-space:nowrap">
                  @if($r->status === 'sent'){{ $r->sent_at ? alocal(\Carbon\Carbon::parse($r->sent_at, 'UTC'), 'M j, g:i a') : 'sent' }}
                  @elseif($r->status === 'pending')<span class="rp-ev">{{ $r->channel === 'personal' ? 'not sent yet' : 'waiting' }}</span>
                  @elseif($r->status === 'skipped')<span class="rp-ev" title="{{ $r->skip_reason }}">skipped · {{ \Illuminate\Support\Str::limit($r->skip_reason ?: 'no reason', 40) }}</span>
                  @else<span class="rp-ev bad" title="{{ $r->error }}">failed</span>@endif
                </td>
                <td>
                  @if($r->opened_at)<span class="rp-ev good">opened {{ alocal(\Carbon\Carbon::parse($r->opened_at, 'UTC'), 'M j, g:i a') }}</span>@endif
                  @if($r->clicked_at)<span class="rp-ev good">clicked {{ alocal(\Carbon\Carbon::parse($r->clicked_at, 'UTC'), 'M j, g:i a') }}</span>@endif
                  @if($r->replied_at)<span class="rp-ev good">replied {{ alocal(\Carbon\Carbon::parse($r->replied_at, 'UTC'), 'M j, g:i a') }}</span>@endif
                  @if($r->bounced_at)<span class="rp-ev bad">bounced · {{ $r->bounce_type ?: 'bounce' }}</span>@endif
                  @if($r->complained_at)<span class="rp-ev bad">marked spam</span>@endif
                  @if($r->unsubscribed_at)<span class="rp-ev warn">unsubscribed</span>@endif
                  @if($r->status === 'sent' && ! $r->opened_at && ! $r->bounced_at)<span style="opacity:.4;font-size:12px">no opens yet</span>@endif
                </td>
                <td style="text-align:right;white-space:nowrap">
                  @if($r->thread_id && $r->replied_at)<a class="pc-edit" href="/admin/inbox?id={{ $r->thread_id }}" target="_blank">Open reply</a>@endif
                  @if($canPersonal && $r->source_type !== 'prospects' && in_array($r->status, ['pending', 'sent', 'failed'], true) && ! $r->unsubscribed_at && ! $r->complained_at)
                    <button type="button" class="pc-edit" style="margin-left:10px" wire:click="sendPersonal('{{ $r->id }}')" wire:loading.attr="disabled" wire:target="sendPersonal('{{ $r->id }}')">{{ $r->status === 'sent' ? 'Send again' : 'Send' }}</button>
                    @if($r->send_count > 1)<div class="em" style="text-align:right">sent {{ $r->send_count }} times{{ $r->channel === 'personal' ? ', last personally' : '' }}</div>@endif
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="4" style="text-align:center;opacity:.5;padding:22px">
                {{ trim($rSearch) !== '' ? 'Nobody matches "' . $rSearch . '".' : 'Nobody here yet.' }}
                @if($rFilter !== 'all') <button type="button" class="pc-edit" wire:click="setReportFilter('all')">Show everyone</button>@endif
              </td></tr>
            @endforelse
          </tbody>
        </table>
        @if($L['pages'] > 1)
          <div class="rp-pager">
            <span style="opacity:.6">{{ number_format($L['from']) }}–{{ number_format($L['to']) }} of {{ number_format($L['total']) }}</span>
            <button type="button" class="pc-btn" wire:click="reportPage({{ $L['page'] - 1 }})" @disabled($L['page'] <= 1)>Previous</button>
            <button type="button" class="pc-btn" wire:click="reportPage({{ $L['page'] + 1 }})" @disabled($L['page'] >= $L['pages'])>Next</button>
          </div>
        @endif

        <div class="pc-note">
          Rates are out of delivered emails (sent minus bounced); bounces are out of sent. Opens are approximate: some mail apps open every email automatically and others block the tracking image, and a click always counts as an open.
          Bounces, spam marks and unsubscribes are tied to the email that caused them from today on; ones from before today are not on this report. An unsubscribe from the link in an email goes on the newest campaign that address was sent.
          @if(in_array($campaign->status, ['sending', 'scheduled'], true)) This report refreshes every 20 seconds while the campaign is going out. @endif
        </div>
      </div>
    @elseif($campaign)
      <div class="pc-cols">
        <div class="pc-card">
          <button type="button" class="pc-back" wire:click="closeCampaign">&larr; All campaigns</button>
          <h3>{{ $campaign->name }}</h3>
          <div class="sub">{{ ucfirst($campaign->status) }}@if($campaign->scheduled_at) · {{ alocal($campaign->scheduled_at, 'D M j, g:i A') }}@endif
            @if($campaign->status !== 'draft') · <button type="button" class="pc-edit" style="font-size:12px" wire:click="openReport('{{ $campaign->id }}')">Report &rarr;</button>@endif
          </div>

          <div class="pc-f"><label>Name</label><input type="text" wire:model="cName"></div>
          <div class="pc-f"><label>Subject</label><input type="text" wire:model="cSubject"></div>
          <div class="pc-f">
            <label>Audience</label>
            <select wire:model.live="cAudience" style="width:100%;background:transparent;border:1px solid var(--pc-line);border-radius:8px;color:inherit;font:inherit;font-size:13.5px;padding:8px 10px">
              <option value="">Pick an audience…</option>
              @foreach($audiences as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
              @endforeach
            </select>
          </div>
          {{-- MARKER-PERSONAL-DELIVERY --}}
          @php
            $dSource = optional($audiences->firstWhere('id', $cAudience))->source;
            $dKey = $dSource === 'prospects' ? 'bulk'
                  : (in_array($cDelivery, ['bulk', 'personal'], true) ? $cDelivery
                  : (in_array($dSource, ['investors', 'inv_leads'], true) ? 'personal' : 'bulk'));
          @endphp
          {{-- MARKER-CAMPAIGN-SENDER --}}
          @php
            $sKey  = in_array($cSender, ['platform', 'outreach'], true) ? $cSender
                   : (optional($audiences->firstWhere('id', $cAudience))->source === 'prospects' ? 'outreach' : 'platform');
            $sPlat = [\App\Services\Platform\PlatformMailer::fromAddress(), \App\Services\Platform\PlatformMailer::fromName()];
            $sOut  = \App\Models\PlatformSettings::outreachFrom();
            $sNow  = $sKey === 'outreach' ? $sOut : $sPlat;
          @endphp
          <div class="pc-f">
            <label>From</label>
            @if($dKey === 'personal')
              {{-- MARKER-PERSONAL-DELIVERY: personal email always comes from you --}}
              <div style="font-size:13.5px">{{ $sOut[1] }} &lt;{{ $sOut[0] }}&gt;</div>
              <div class="pc-note" style="margin-top:6px">One at a time always sends from you. Replies come back to the Inbox under the person who answered.</div>
            @else
            <div class="pl-seg">
              <button type="button" class="{{ $sKey === 'platform' ? 'on' : '' }}" wire:click="setSender('platform')">{{ $sPlat[1] }}</button>
              <button type="button" class="{{ $sKey === 'outreach' ? 'on' : '' }}" wire:click="setSender('outreach')">{{ $sOut[1] }} (outreach)</button>
            </div>
            <div class="pc-note" style="margin-top:6px">
              {{ $sNow[1] }} &lt;{{ $sNow[0] }}&gt;{{ $cSender === '' ? ' · automatic: your outreach sender for prospects, Intake for everyone else' : '' }}.
              Replies come back to the Inbox under the person who answered.
            </div>
            @endif
          </div>
          @if($dSource !== 'prospects')
            <div class="pc-f">
              <label>How it goes out</label>
              <div class="pl-seg">
                <button type="button" class="{{ $dKey === 'bulk' ? 'on' : '' }}" wire:click="setDelivery('bulk')">All at once</button>
                <button type="button" class="{{ $dKey === 'personal' ? 'on' : '' }}" wire:click="setDelivery('personal')">One at a time</button>
              </div>
              <div class="pc-note" style="margin-top:6px">
                @if($dKey === 'personal')
                  Each person gets their own email from {{ $sOut[1] }}, sent when you press Send next to their name, on the same stream as receipts and replies. No bulk-mail headers, so it lands like a personal email.
                @else
                  The campaign goes out on the platform campaign stream, paced by the worker.
                @endif
                {{ $cDelivery === '' ? ' Automatic: one at a time for investors, all at once for everyone else.' : '' }}
              </div>
            </div>
          @endif
          {{-- plain text, or a letter built from blocks --}}
          <div class="pc-f">
            <label>Content</label>
            <div class="pl-seg">
              <button type="button" class="{{ $cMode === 'body' ? 'on' : '' }}" wire:click="useBody">Plain text</button>
              <button type="button" class="{{ $cMode === 'letter' ? 'on' : '' }}" wire:click="useLetter">Letter</button>
            </div>
          </div>
          @if($cMode === 'letter')
            <div class="pc-f">
              <label>Sends in</label>
              <div class="pl-seg">
                <button type="button" class="{{ $cSendTheme === 'light' ? 'on' : '' }}" wire:click="setSendTheme('light')">Light</button>
                <button type="button" class="{{ $cSendTheme === 'dark' ? 'on' : '' }}" wire:click="setSendTheme('dark')">Dark</button>
              </div>
            </div>
            <div class="pc-note" style="margin:-4px 0 10px">
              Tokens: <code>{first_name}</code> <code>{shop_name}</code> — and for investors <code>{amount}</code> <code>{percent}</code> <code>{remaining}</code> <code>{portal}</code>.
              The preview on the right uses sample values.
            </div>
            @if(count($cBlocks) > 1)
              <div class="pl-tools">
                <span style="opacity:.6">{{ count($cBlocks) }} blocks</span>
                <span>
                  <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('pl-fold', { detail: true }))">Fold all</button>
                  <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('pl-fold', { detail: false }))">Unfold all</button>
                </span>
              </div>
            @endif
            <div class="pl-list">
            @foreach($cBlocks as $i => $blk)
              @php $isFeat = str_starts_with($blk['type'], 'feat_'); @endphp
              @if($i > 0)
                <div class="pl-ins" x-data="{ pick: false }" x-bind:class="{ on: pick }" wire:key="ins-{{ $i }}">
                  <button type="button" x-on:click="pick = !pick" title="Insert a block here">+</button>
                  <div class="pl-pick" x-show="pick" x-on:click.outside="pick = false" x-cloak>
                      <div class="pl-add-g"><span>Letter</span>
                        @foreach($letterTypes as $lt => $ll)
                          @unless(str_starts_with($lt, 'feat_'))<button type="button" class="pc-btn" wire:click="insertLetterBlock({{ $i }}, '{{ $lt }}')" x-on:click="pick = false">+ {{ $ll }}</button>@endunless
                        @endforeach
                      </div>
                      <div class="pl-add-g pl-add-feat"><span>Feature</span>
                        @foreach($letterTypes as $lt => $ll)
                          @if(str_starts_with($lt, 'feat_'))<button type="button" class="pc-btn" wire:click="insertLetterBlock({{ $i }}, '{{ $lt }}')" x-on:click="pick = false">+ {{ $ll }}</button>@endif
                        @endforeach
                      </div>
                    </div>
                </div>
              @endif
              <div class="pl-blk" wire:key="lb-{{ $i }}-{{ $blk['type'] }}" x-data="{ open: true }" x-on:pl-fold.window="open = ! $event.detail">
                <div class="pl-blk-h" x-on:click="open = !open">
                  <b>{{ $letterTypes[$blk['type']] ?? $blk['type'] }}</b>
                  <span class="pl-kind {{ $isFeat ? 'pl-kind--feat' : '' }}">{{ $isFeat ? 'Feature' : 'Letter' }}</span>
                  <span class="pl-sum" x-show="! open">{{ \App\Support\PlatformLetter::summary($blk) }}</span>
                  <span class="pl-ctl" x-on:click.stop>
                    <button type="button" wire:click="moveLetterBlock({{ $i }}, -1)" title="Move up">↑</button>
                    <button type="button" wire:click="moveLetterBlock({{ $i }}, 1)" title="Move down">↓</button>
                    <button type="button" wire:click="removeLetterBlock({{ $i }})" title="Remove">✕</button>
                  </span>
                </div>
                <div class="pl-blk-b" x-show="open">
                @switch($blk['type'])
                  @case('letter_text')
                    <textarea rows="6" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.text" placeholder="Write it like a note. Blank lines make paragraphs."></textarea>
                    @break
                  @case('letter_numbers')
                    <div class="pl-grid">
                      @for($h = 0; $h < 4; $h++)
                        <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.headers.{{ $h }}" placeholder="{{ ['Label', 'Last month', 'This month', 'Change'][$h] }}" class="pl-h">
                      @endfor
                      @foreach(($blk['data']['rows'] ?? []) as $r => $row)
                        @for($k = 0; $k < 4; $k++)
                          <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.rows.{{ $r }}.{{ $k }}">
                        @endfor
                      @endforeach
                    </div>
                    <div style="display:flex;gap:6px;margin-top:6px">
                      <button type="button" class="pc-btn" wire:click="addNumbersRow({{ $i }})">+ Row</button>
                      @if(count($blk['data']['rows'] ?? []) > 1)
                        <button type="button" class="pc-btn" wire:click="removeNumbersRow({{ $i }}, {{ count($blk['data']['rows']) - 1 }})">− Last row</button>
                      @endif
                    </div>
                    @break
                  @case('letter_shot')
                    @if(! empty($blk['data']['url']))
                      <img src="{{ $blk['data']['url'] }}" alt="" style="max-width:100%;border-radius:6px;border:1px solid var(--pc-line);margin-bottom:6px">
                    @endif
                    {{-- an app button, not the browser's control --}}
                    <label class="pc-btn pl-file">Choose image<input type="file" accept="image/*" wire:model="shotUploads.{{ $i }}"></label>
                    <span class="pc-note" wire:loading wire:target="shotUploads.{{ $i }}">Uploading…</span>
                    @if(! empty($shotUploads[$i]))
                      <span class="pc-note">{{ $shotUploads[$i]->getClientOriginalName() }} — saved when you Save draft</span>
                    @endif
                    <div class="pc-note" style="margin:6px 0 6px">Or paste an image link:</div>
                    <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.url" placeholder="https://…">
                    <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.caption" placeholder="Caption" style="margin-top:6px">
                    @break
                  @case('letter_ask')
                    <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.title" placeholder="What would help">
                    <textarea rows="4" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.items" placeholder="One ask per line" style="margin-top:6px"></textarea>
                    @break
                  @case('letter_signature')
                    <textarea rows="3" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.text"></textarea>
                    <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.small" placeholder="Name · title · email" style="margin-top:6px">
                    @break
                  @case('feat_hero')
                    <label class="pl-l">Small line above <span style="opacity:.7">(optional)</span></label>
                    <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.label" placeholder="November update">
                    <label class="pl-l">Headline</label>
                    <textarea rows="2" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.text" placeholder="Finding the margin you already have."></textarea>
                    @break
                  @case('feat_display')
                  @case('feat_headline')
                    <label class="pl-l">Text</label>
                    <textarea rows="2" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.text"></textarea>
                    @break
                  @case('feat_lede')
                    <label class="pl-l">Text <span style="opacity:.7">— set larger than a letter paragraph</span></label>
                    <textarea rows="3" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.text"></textarea>
                    @break
                  @case('feat_section')
                    <label class="pl-l">Style</label>
                    <div class="pl-seg">
                      @foreach(['square' => 'Numbered square', 'bars' => 'Stepped bars', 'rule' => 'Split rule'] as $sk => $sl)
                        <button type="button" class="{{ ($blk['data']['style'] ?? 'square') === $sk ? 'on' : '' }}" wire:click="setSectionStyle({{ $i }}, '{{ $sk }}')">{{ $sl }}</button>
                      @endforeach
                    </div>
                    <div class="pl-row" style="grid-template-columns:90px 1fr">
                      <div><label class="pl-l">Number</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.num" placeholder="03"></div>
                      <div><label class="pl-l">Label</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.text" placeholder="How Intake fixes it"></div>
                    </div>
                    @break
                  @case('feat_receipt')
                    <label class="pl-l">Title <span style="opacity:.7">(optional)</span></label>
                    <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.title" placeholder="One shop · 30 days">
                    <label class="pl-l">Lines</label>
                    @foreach(($blk['data']['rows'] ?? []) as $r => $row)
                      <div class="pl-row" style="grid-template-columns:1.6fr 1fr;margin-bottom:6px">
                        <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.rows.{{ $r }}.0" placeholder="Duplicates merged">
                        <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.rows.{{ $r }}.1" placeholder="1,184">
                      </div>
                    @endforeach
                    <div style="display:flex;gap:6px">
                      <button type="button" class="pc-btn" wire:click="addReceiptRow({{ $i }})">+ Line</button>
                      @if(count($blk['data']['rows'] ?? []) > 1)
                        <button type="button" class="pc-btn" wire:click="removeReceiptRow({{ $i }}, {{ count($blk['data']['rows']) - 1 }})">− Last line</button>
                      @endif
                    </div>
                    <div class="pl-row" style="grid-template-columns:1.6fr 1fr">
                      <div><label class="pl-l">Total line <span style="opacity:.7">(optional, highlighted)</span></label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.total_label" placeholder="Margin"></div>
                      <div><label class="pl-l">&nbsp;</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.total_value" placeholder="41.6%"></div>
                    </div>
                    @break
                  @case('feat_tiles')
                    <label class="pl-l">Tiles</label>
                    <div class="pl-seg">
                      <button type="button" class="{{ count($blk['data']['tiles'] ?? []) === 2 ? 'on' : '' }}" wire:click="setTileCount({{ $i }}, 2)">Two</button>
                      <button type="button" class="{{ count($blk['data']['tiles'] ?? []) === 4 ? 'on' : '' }}" wire:click="setTileCount({{ $i }}, 4)">Four</button>
                    </div>
                    <div class="pl-row" style="margin-top:8px">
                      @foreach(($blk['data']['tiles'] ?? []) as $k => $tile)
                        <div class="pl-tilebox" style="border-left-color:{{ ['#F2CC57', '#F09CC4', '#BEF264', '#8FB0F5'][$k % 4] }}">
                          <label class="pl-l" style="margin-top:2px">Label</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.tiles.{{ $k }}.label" placeholder="Duplicates merged">
                          <label class="pl-l">Figure</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.tiles.{{ $k }}.value" placeholder="1,184">
                          <label class="pl-l">Note</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.tiles.{{ $k }}.note" placeholder="across three distributors">
                        </div>
                      @endforeach
                    </div>
                    @break
                  @case('feat_tray')
                  @case('feat_annotated')
                    <label class="pl-l">Screenshot</label>
                    <label class="pc-btn pl-file">{{ empty($blk['data']['url']) ? 'Choose image' : 'Replace image' }}<input type="file" accept="image/*" wire:model="shotUploads.{{ $i }}"></label>
                    <span class="pc-note" wire:loading wire:target="shotUploads.{{ $i }}">Uploading…</span>
                    @if($blk['type'] === 'feat_tray')
                      <div class="pl-row">
                        <div><label class="pl-l">Tab name</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.tab" placeholder="Register"></div>
                        <div><label class="pl-l">Caption <span style="opacity:.7">(optional)</span></label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.caption"></div>
                      </div>
                      @if(! empty($blk['data']['url']))
                        <div class="pl-shot" style="cursor:default"><img src="{{ $blk['data']['url'] }}" alt=""></div>
                      @endif
                    @else
                      @if(! empty($blk['data']['url']))
                        <div class="pc-note" style="margin-top:8px">Click the picture to drop a numbered marker (up to six). Each marker gets a note below the image in the email.</div>
                        <div class="pl-shot" x-on:click="const r = $event.currentTarget.getBoundingClientRect(); $wire.addMarker({{ $i }}, ($event.clientX - r.left) / r.width * 100, ($event.clientY - r.top) / r.height * 100)">
                          <img src="{{ $blk['data']['url'] }}" alt="">
                          @foreach(($blk['data']['markers'] ?? []) as $k => $mk)
                            <span class="pl-pin" style="left:{{ $mk['x'] }}%;top:{{ $mk['y'] }}%">{{ $k + 1 }}</span>
                          @endforeach
                        </div>
                        @foreach(($blk['data']['markers'] ?? []) as $k => $mk)
                          <div class="pl-mk">
                            <i>{{ $k + 1 }}</i>
                            <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.markers.{{ $k }}.title" placeholder="Bold lead-in">
                            <input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.markers.{{ $k }}.note" placeholder="What this shows">
                            <button type="button" wire:click="removeMarker({{ $i }}, {{ $k }})" title="Remove marker">✕</button>
                          </div>
                          {{-- MARKER-MARKER-SHOTS --}}
                          <div class="pl-mk-shot">
                            @if(! empty($mk['shot']))
                              <img src="{{ $mk['shot'] }}" alt="">
                              <button type="button" class="pc-btn" wire:click="removeMarkerShot({{ $i }}, {{ $k }})">Remove picture</button>
                            @else
                              <label class="pc-btn pl-file">Add a close-up for {{ $k + 1 }}<input type="file" accept="image/*" wire:model="markerUploads.{{ $i }}.{{ $k }}"></label>
                              <span class="pc-note" style="margin:0">Optional. Shows under this note in the email.</span>
                            @endif
                          </div>
                        @endforeach
                      @else
                        <div class="pc-note">Pick a screenshot, then click on it to place markers.</div>
                      @endif
                    @endif
                    @break
                  @case('feat_divider')
                    <div class="pc-note" style="margin-top:6px">A perforated line between sections. Nothing to fill in.</div>
                    @break
                  @case('feat_button')
                    <div class="pl-row">
                      <div><label class="pl-l">Label</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.label" placeholder="See it in the demo"></div>
                      <div><label class="pl-l">Link</label><input type="text" wire:model.live.debounce.700ms="cBlocks.{{ $i }}.data.url" placeholder="https://… or {portal}"></div>
                    </div>
                    @break
                @endswitch
                </div>
              </div>
            @endforeach
            </div>
            <div class="pl-add">
              <div class="pl-add-g"><span>Letter</span>
                @foreach($letterTypes as $lt => $ll)
                  @unless(str_starts_with($lt, 'feat_'))<button type="button" class="pc-btn" wire:click="addLetterBlock('{{ $lt }}')">+ {{ $ll }}</button>@endunless
                @endforeach
              </div>
              <div class="pl-add-g pl-add-feat"><span>Feature</span>
                @foreach($letterTypes as $lt => $ll)
                  @if(str_starts_with($lt, 'feat_'))<button type="button" class="pc-btn" wire:click="addLetterBlock('{{ $lt }}')">+ {{ $ll }}</button>@endif
                @endforeach
              </div>
            </div>
          @else
          <div class="pc-f">
            <label>Body</label>
            <textarea wire:model="cBody" placeholder="Write the email. Blank lines make paragraphs."></textarea>
            <div class="pc-tok"><code>&#123;&#123;first_name&#125;&#125;</code><code>&#123;&#123;shop_name&#125;&#125;</code></div>
          </div>
          @endif
          @if($dKey !== 'personal')
          <div class="pc-f">
            <label>Send at <span style="opacity:.55">(leave blank to send now)</span></label>
            <input type="datetime-local" wire:model="cSchedule" title="{{ admin_tz_label() }}"> <span style="font-size:11.5px;opacity:.6">{{ admin_tz_label() }}</span>
          </div>
          @endif

          @if($blockers)
            <div class="pc-note" style="color:#f0c46a">
              @foreach($blockers as $b){{ $b }} @endforeach
            </div>
          @endif

          {{-- MARKER-CAMPAIGN-TEST --}}
          <div class="pc-f">
            <label>Send a test</label>
            <div style="display:flex;gap:8px">
              <input type="email" wire:model="cTestTo" placeholder="you@intake.works" style="flex:1">
              <button type="button" class="pc-btn" wire:click="sendCampaignTest" wire:loading.attr="disabled" wire:target="sendCampaignTest">Send test</button>
            </div>
            <div class="pc-note" style="margin-top:6px">One copy, exactly as it will go out, filled in with the first person in the audience and "[TEST]" on the subject. Saves the draft first.</div>
          </div>

          <div class="pc-acts">
            <button type="button" class="pc-btn" wire:click="saveCampaign">Save draft</button>
            @if($dKey === 'personal')
              <button type="button" class="pc-btn pc-btn--pri" wire:click="startPersonal" @disabled(count($blockers) > 0)>Prepare the list</button>
            @else
            <button type="button" class="pc-btn pc-btn--pri" wire:click="scheduleCampaign" @disabled(count($blockers) > 0)>
              {{ trim($cSchedule) !== '' ? 'Schedule' : 'Send now' }}
            </button>
            @endif
            @if(in_array($campaign->status, ['scheduled', 'sending'], true))
              <button type="button" class="pc-btn pc-btn--warn" wire:click="cancelCampaign">Cancel</button>
            @endif
            <button type="button" class="pc-btn" wire:click="closeCampaign">Close</button>
          </div>
        </div>

        <div class="pc-card pc-sticky">
          <h3>Who this reaches</h3>
          <div class="sub">Counted by the same rules the sender uses</div>
          @if($reach)
            <div style="font-size:30px;font-weight:800;letter-spacing:-.02em">{{ $reach['mailable'] }}</div>
            <div class="pc-note" style="margin-top:2px">
              of {{ $reach['matched'] }} matched — the difference is people who unsubscribed.
              @if($reach['names'])<br>{{ implode(', ', $reach['names']) }}@if($reach['mailable'] > count($reach['names'])) and {{ $reach['mailable'] - count($reach['names']) }} more @endif @endif
            </div>
          @else
            <div class="pc-note">Pick an audience to see the reach.</div>
          @endif

          {{-- live preview, light / dark / phone --}}
          @if($letterPreview)
            <div style="margin-top:18px;border-top:1px solid var(--pc-line);padding-top:14px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <div class="pc-grp" style="padding:0">Preview</div>
                <div class="pl-seg">
                  <button type="button" class="{{ $cTheme === 'light' ? 'on' : '' }}" wire:click="setLetterTheme('light')">Light</button>
                  <button type="button" class="{{ $cTheme === 'dark' ? 'on' : '' }}" wire:click="setLetterTheme('dark')">Dark</button>
                  <button type="button" class="{{ $cTheme === 'phone' ? 'on' : '' }}" wire:click="setLetterTheme('phone')">Phone</button>
                </div>
              </div>
              <div class="pc-frame" style="{{ $cTheme === 'phone' ? 'max-width:380px;margin:0 auto' : '' }}">
                <iframe srcdoc="{{ $letterPreview }}" title="Letter preview" style="height:640px"></iframe>
              </div>
              <div class="pc-note">Sample values stand in for the tokens. This campaign sends in {{ $cSendTheme === 'dark' ? 'Dark' : 'Light' }} (set above); the other theme shows roughly how it looks the other way round. Phone shows the sending theme at phone width.</div>
            </div>
          @endif

          @if($campaign->total_sent)
            <div style="margin-top:18px;border-top:1px solid var(--pc-line);padding-top:14px">
              <div class="pc-grp" style="padding-top:0">Results</div>
              <div style="font-size:13.5px;line-height:1.9">
                Sent <b>{{ $campaign->total_sent }}</b> of {{ $campaign->total_recipients }}<br>
                Opened <b>{{ (int) $campaign->total_opened }}</b> · clicked <b>{{ (int) $campaign->total_clicked }}</b> · replies arrive in your inbox<br>
                @if($campaign->sent_at)Finished {{ $campaign->sent_at->diffForHumans() }}@endif
              </div>
            </div>
          @endif
        </div>
      </div>
    @else
      <div style="display:flex;gap:8px;margin-bottom:14px">
        <button type="button" class="pc-btn pc-btn--pri" wire:click="newCampaign">New campaign</button>
      </div>

      <div class="pc-list" @if($campaigns->contains(fn ($x) => $x->status === 'sending')) wire:poll.30s @endif>
        @forelse($campaigns as $c)
          <div class="pc-row" style="grid-template-columns:1fr 110px 1fr 150px">
            <div>
              <div class="t">{{ $c->name }}</div>
              <div class="d">{{ $c->subject ?: 'No subject yet' }}</div>
            </div>
            <div><span class="pc-pill {{ $c->status === 'sent' ? 'pc-pill--custom' : '' }}">{{ ucfirst($c->status) }}</span></div>
            <div class="fires">
              {{ optional($c->audience)->name ?: 'No audience' }}
              @php $lc = $listCounts[$c->id] ?? null; @endphp
              @if($c->status === 'scheduled' && $c->scheduled_at)
                <div class="d">starts {{ alocal($c->scheduled_at, 'D M j, g:i a') }}</div>
              @elseif($lc && $lc['pending'] > 0)
                {{-- MARKER-CAMPAIGN-REPORT: sending progress --}}
                <div class="d">{{ number_format($lc['sent']) }} of {{ number_format($lc['total']) }} sent · {{ number_format($lc['pending']) }} waiting{{ $lc['eta_minutes'] ? ' · ~' . ($lc['eta_minutes'] < 90 ? $lc['eta_minutes'] . ' min' : round($lc['eta_minutes'] / 60, 1) . ' h') . ' left' : '' }}</div>
                <div class="pc-progline"><i style="width:{{ $lc['progress'] }}%"></i></div>
              @elseif($lc && $lc['sent'] > 0)
                <div class="d">{{ number_format($lc['sent']) }} sent · {{ $lc['open_rate'] ?? 0 }}% opened · {{ $lc['click_rate'] ?? 0 }}% clicked{{ $lc['replied'] ? ' · ' . $lc['replied'] . ' replied' : '' }}{{ $lc['bounced'] ? ' · ' . $lc['bounced'] . ' bounced' : '' }}</div>
              @endif
            </div>
            <div class="pc-acts-row">
              @if($confirmDel === 'c:' . $c->id)
                <span style="opacity:.7">Delete?</span>
                <button type="button" class="pc-del" wire:click="deleteCampaign('{{ $c->id }}')">Yes</button>
                <button type="button" class="pc-edit" wire:click="cancelDelete">No</button>
              @else
                @if($c->status === 'draft')<button type="button" class="pc-del" wire:click="askDelete('c:{{ $c->id }}')">Delete</button>@endif
                @if($c->status !== 'draft')<button type="button" class="pc-edit" wire:click="openReport('{{ $c->id }}')">Report</button>@endif
                <button type="button" class="pc-edit" wire:click="openCampaign('{{ $c->id }}')">Open</button>
              @endif
            </div>
          </div>
        @empty
          <div style="padding:26px 16px;text-align:center;opacity:.5;font-size:13px">No campaigns yet.</div>
        @endforelse
      </div>

      <div class="pc-grp">Audiences</div>
      <div class="pc-list">
        @forelse($audiences as $a)
          <div class="pc-row" style="grid-template-columns:1fr 150px 1fr 150px{{ $aEditing === $a->id ? ';background:rgba(139,124,246,.08)' : '' }}">
            <div><div class="t">{{ $a->name }}</div></div>
            <div><span class="pc-pill">{{ \App\Models\PlatformAudience::SOURCES[$a->source] ?? $a->source }}</span></div>
            <div class="fires">{{ count($a->rules ?? []) }} {{ count($a->rules ?? []) === 1 ? 'rule' : 'rules' }}</div>
            <div class="pc-acts-row">
              @if($confirmDel === 'a:' . $a->id)
                <span style="opacity:.7">Delete?</span>
                <button type="button" class="pc-del" wire:click="deleteAudience('{{ $a->id }}')">Yes</button>
                <button type="button" class="pc-edit" wire:click="cancelDelete">No</button>
              @else
                <button type="button" class="pc-del" wire:click="askDelete('a:{{ $a->id }}')">Delete</button>
                <button type="button" class="pc-edit" wire:click="openAudience('{{ $a->id }}')">Open</button>
              @endif
            </div>
          </div>
        @empty
          <div style="padding:20px 16px;text-align:center;opacity:.5;font-size:13px">No audiences yet — make one below.</div>
        @endforelse
      </div>

      <!-- MARKER-AUDIENCE-BUILDER -->
      <style>
        .ab{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:28px;align-items:start}
        @media(max-width:1000px){.ab{grid-template-columns:1fr}}
        .ab-src{display:flex;flex-wrap:wrap;gap:6px}
        .ab-src button{border:1px solid var(--pc-line);background:none;color:inherit;font:inherit;font-size:13px;border-radius:8px;padding:7px 12px;cursor:pointer;opacity:.75}
        .ab-src button.on{border-color:var(--pc-accent);background:rgba(139,124,246,.12);opacity:1;font-weight:600}
        .ab-rule{display:grid;grid-template-columns:44px minmax(0,1fr) 110px minmax(0,1.3fr) 28px;gap:8px;align-items:center;margin-bottom:8px}
        .ab-rule .j{font-size:12px;opacity:.5;text-align:right}
        .ab-rule select,.ab-rule input{width:100%;background:transparent;border:1px solid var(--pc-line);border-radius:8px;color:inherit;font:inherit;font-size:13.5px;padding:7px 9px}
        .ab-rule select option{color:#111}
        .ab-x{background:none;border:0;color:inherit;opacity:.5;cursor:pointer;font-size:17px}
        .ab-x:hover{opacity:1}
        .ab-add{background:none;border:0;color:var(--pc-accent);font:inherit;font-weight:600;font-size:13px;cursor:pointer;padding:2px 0}
        .ab-chips{display:flex;flex-wrap:wrap;gap:6px}
        .ab-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--pc-line);border-radius:99px;padding:5px 11px;font-size:12.5px;background:none;color:inherit;font-family:inherit;cursor:pointer;opacity:.7}
        .ab-chip.on{opacity:1;border-color:rgba(248,113,113,.5);background:rgba(248,113,113,.08)}
        .ab-chip.lock{cursor:default}
        .ab-gate{border-left:2px solid var(--pc-warn);padding:6px 0 6px 12px;font-size:12.5px;line-height:1.55;opacity:.9;margin-top:10px}
        .ab-gate.inv{border-left-color:var(--pc-accent)}
        .ab-gate b{font-weight:600}
        .ab-prev{border-left:1px solid var(--pc-line);padding-left:22px}
        @media(max-width:1000px){.ab-prev{border-left:0;padding-left:0;border-top:1px solid var(--pc-line);padding-top:16px}}
        .ab-big{font-size:44px;font-weight:700;letter-spacing:-.03em;line-height:1;font-variant-numeric:tabular-nums}
        .ab-big small{font-size:13px;font-weight:500;opacity:.6;margin-left:6px;letter-spacing:0}
        .ab-math div{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--pc-line);font-size:13px;opacity:.8;font-variant-numeric:tabular-nums}
        .ab-math div.m b{color:#f87171}
        .ab-math div.t{border-bottom:0;opacity:1;font-weight:600}
        .ab-s{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;padding:7px 0;border-bottom:1px solid var(--pc-line);font-size:13px}
        .ab-s:last-child{border-bottom:0}
        .ab-pg{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:18px 0 6px}
        .ab-pg-b{display:flex;gap:6px}
        .ab-pg-b .pc-btn{padding:4px 10px;font-size:12px}
        .ab-pg-b .pc-btn:disabled{opacity:.35;cursor:default}
        .ab-s .e{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;opacity:.5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ab-s .tg{font-size:11.5px;opacity:.6;white-space:nowrap}
      </style>
      @php
        $abF   = $aScreen['fields'] ?? [];
        $abO   = $aScreen['options'] ?? [];
        $abP   = $aScreen['preview'] ?? null;
        $abInv = in_array($aSource, ['investors', 'inv_leads'], true);
        $abEx  = \App\Services\Platform\PlatformAudienceService::EXCLUSIONS;
      @endphp
      <div class="pc-card" style="margin-top:14px">
        <h3>{{ $aEditing ? 'Edit audience' : 'New audience' }}</h3>
        <div class="sub">Rules, not a fixed list. Who matches is worked out again every time a campaign sends.</div>
        <div class="ab">
          <div>
            <div class="pc-f"><label>Name</label><input type="text" wire:model="aName" placeholder="Leave blank to name it from the rules"></div>

            <div class="pc-f">
              <label>Who</label>
              <div class="ab-src">
                @foreach(\App\Models\PlatformAudience::SOURCES as $k => $label)
                  <button type="button" class="{{ $aSource === $k ? 'on' : '' }}" wire:click="setSource('{{ $k }}')">{{ $label }}</button>
                @endforeach
              </div>
              @if(isset(\App\Models\PlatformAudience::SOURCE_HELP[$aSource]))
                <div class="pc-note" style="margin-top:8px;opacity:.75">{{ \App\Models\PlatformAudience::SOURCE_HELP[$aSource] }}</div>
              @endif
              @if($aSource === 'prospects')
                <div class="ab-gate"><b>Every prospect with an email.</b> Prospects who never opted in (no call booked, never wrote in, not a tenant) go out one at a time at your outreach pace, business and free addresses apart and after the outreach sender's own last send, so a campaign never arrives as a burst. Everyone else sends straight away. Filter with Contacted and Last email sent.</div>
              @endif
              @if($abInv)
                <div class="ab-gate inv"><b>Investor channel, kept apart.</b> Use investor audiences for investor updates only. Declined investors are always left out, and the investor details ({amount}, {percent}, {remaining}, {portal}) can't be sent to any other audience. The mailing list is people who asked for updates, not investors; keep what goes to them factual, since an offer sent to a list anyone could join starts to look like general solicitation.</div>
              @endif
            </div>

            @if($abF)
              <div class="pc-f">
                <label>Match all of these</label>
                @foreach($aRules as $i => $r)
                  @php $rf = (string) ($r['field'] ?? ''); @endphp
                  @if(isset($abF[$rf]))
                    <div class="ab-rule" wire:key="abr-{{ $i }}">
                      <span class="j">{{ $i ? 'and' : 'where' }}</span>
                      <select wire:model.live="aRules.{{ $i }}.field">
                        @foreach($abF as $fk => $fl)<option value="{{ $fk }}">{{ $fl }}</option>@endforeach
                      </select>
                      <select wire:model.live="aRules.{{ $i }}.op">
                        @if($rf === 'addon')
                          <option value="has">has</option><option value="has_not">doesn't have</option>
                        @elseif($rf === 'is_active')
                          <option value="is">is</option>
                        @else
                          <option value="is">is</option><option value="is_not">is not</option>
                        @endif
                      </select>
                      @if(! empty($abO[$rf]))
                        <select wire:model.live="aRules.{{ $i }}.value">
                          @foreach($abO[$rf] as $ov => $ol)<option value="{{ $ov }}">{{ $ol }}</option>@endforeach
                        </select>
                      @else
                        <input type="text" wire:model.live.debounce.500ms="aRules.{{ $i }}.value" placeholder="value">
                      @endif
                      <button type="button" class="ab-x" wire:click="removeAudienceRule({{ $i }})" title="Remove this rule">&times;</button>
                    </div>
                  @else
                    <span class="pc-rule">{{ $rf === 'ids' ? 'These ' . count(array_filter(explode(',', (string) ($r['value'] ?? '')))) . ' picked shops' : $rf . ' ' . str_replace('_', ' ', (string) ($r['op'] ?? 'is')) . ' ' . \Illuminate\Support\Str::limit((string) ($r['value'] ?? ''), 40) }}<button type="button" wire:click="removeAudienceRule({{ $i }})" title="Remove this rule">&times;</button></span>
                  @endif
                @endforeach
                @if(! count($aRules))<div class="pc-note" style="margin-bottom:6px">No rules: everyone in this source.</div>@endif
                <button type="button" class="ab-add" wire:click="addRule">+ Add rule</button>
              </div>
            @endif

            <div class="pc-f">
              <label>Leave out</label>
              <div class="ab-chips">
                <span class="ab-chip on lock">Opted out &middot; always</span>
                <span class="ab-chip on lock">Bounced &middot; always</span>
                @if($aSource === 'investors')<span class="ab-chip on lock">Declined &middot; always</span>@endif
                @if($aSource === 'prospects')
                  @foreach($abEx as $ek => $ex)
                    <button type="button" class="ab-chip {{ in_array($ek, $aExclude, true) ? 'on' : '' }}" wire:click="toggleExclude('{{ $ek }}')">{{ $ex['label'] }}</button>
                  @endforeach
                @endif
              </div>
            </div>

            <div class="pc-acts">
              <button type="button" class="pc-btn pc-btn--pri" wire:click="newAudience">{{ $aEditing ? 'Save changes' : 'Save audience' }}</button>
              @if($aEditing)<button type="button" class="pc-btn" wire:click="closeAudience">Cancel</button>@endif
            </div>
          </div>

          <div class="ab-prev" wire:loading.class="opacity-60">
            <div class="pc-grp" style="margin-top:0">Will receive right now</div>
            @if($abP)
              <div class="ab-big">{{ number_format($abP['mailable']) }}<small>{{ $abP['mailable'] === 1 ? 'person' : 'people' }}</small></div>
              <div class="pc-note" style="margin:8px 0 12px">{{ $abInv ? 'Investor updates only' : 'Postmark broadcast stream' }}</div>
              <div class="ab-math">
                <div><span>Match the rules</span><b>{{ number_format($abP['matched']) }}</b></div>
                @if($abP['optout'] > 0)<div class="m"><span>Opted out</span><b>&minus;{{ number_format($abP['optout']) }}</b></div>@endif
                @if($abP['bounced'] > 0)<div class="m"><span>Bounced or complained</span><b>&minus;{{ number_format($abP['bounced']) }}</b></div>@endif
                <div class="t"><span>Will receive</span><b>{{ number_format($abP['mailable']) }}</b></div>
              </div>
              <div class="ab-pg">
                <div class="pc-grp" style="margin:0">@if($abP['mailable']){{ number_format($abP['from']) }}&ndash;{{ number_format($abP['to']) }} of {{ number_format($abP['mailable']) }}@else People @endif</div>
                @if($abP['pages'] > 1)
                  <div class="ab-pg-b">
                    <button type="button" class="pc-btn" wire:click="audiencePage({{ $abP['page'] - 1 }})" @disabled($abP['page'] <= 1)>Previous</button>
                    <button type="button" class="pc-btn" wire:click="audiencePage({{ $abP['page'] + 1 }})" @disabled($abP['page'] >= $abP['pages'])>Next</button>
                  </div>
                @endif
              </div>
              @forelse($abP['sample'] as $s)
                <div class="ab-s"><div><div>{{ $s['name'] }}</div><div class="e">{{ $s['email'] }}</div></div><div class="tg">{{ $s['tag'] }}</div></div>
              @empty
                <div class="pc-note">No one matches yet. Loosen a rule.</div>
              @endforelse
            @else
              <div class="pc-note">The preview couldn't be worked out. Saving still works.</div>
            @endif
            <div class="pc-note" style="margin-top:14px;line-height:1.55">These are today's numbers; they're worked out again at send time. Opted-out and bounced addresses are always removed. A shop's own customers are never an audience here.</div>
          </div>
        </div>
      </div>
    @endif
  @endif

  {{-- ======================================================== ACTIVITY --}}
  @if($tab === 'activity')
    <div class="pc-legend">
      <b>Everything Intake has sent, newest first</b> — campaign sends and one-off mail together. Automatic
      alerts to you aren't listed; they're in the debug log where they belong.
    </div>

    <div class="pc-list">
      @forelse($activity as $row)
        <div class="pc-row" style="grid-template-columns:150px 1fr 1fr 110px">
          <div class="fires">{{ $row['when']?->diffForHumans() }}</div>
          <div><div class="t" style="font-size:13.5px">{{ $row['email'] }}</div></div>
          <div class="fires">{{ $row['what'] }}</div>
          <div>
            <span class="pc-pill @if($row['status'] === 'failed') pc-pill--custom @endif">{{ ucfirst($row['status']) }}</span>
          </div>
        </div>
      @empty
        <div style="padding:26px 16px;text-align:center;opacity:.5;font-size:13px">Nothing sent yet.</div>
      @endforelse
    </div>
  @endif

  {{-- ==================================================== SUPPRESSIONS --}}
  @if($tab === 'suppressions')
    <div class="pc-legend">
      <b>One list, three reasons.</b> Someone asked to stop ({{ $suppressCounts['unsubscribe'] }}), their mail
      server refused us ({{ $suppressCounts['bounce'] }}), or they marked us as spam
      ({{ $suppressCounts['complaint'] }}). Every one of these is skipped at send time. None of it affects a
      shop's own mail to its customers, or anything transactional about their account.
    </div>

    <div class="pc-list">
      @forelse($suppressions as $row)
        <div class="pc-row" style="grid-template-columns:1fr 150px 1fr 110px">
          <div><div class="t" style="font-size:13.5px">{{ $row->email }}</div></div>
          <div>
            <span class="pc-pill">{{ \App\Models\PlatformEmailOptout::KINDS[$row->kind ?? 'unsubscribe'] ?? 'Suppressed' }}</span>
          </div>
          <div class="fires">{{ $row->detail ?: '—' }}</div>
          <div style="text-align:right">
            <button type="button" class="pc-edit" wire:click="unsuppress('{{ $row->email }}')">Allow again</button>
          </div>
        </div>
      @empty
        <div style="padding:26px 16px;text-align:center;opacity:.5;font-size:13px">Nobody is suppressed.</div>
      @endforelse
    </div>

    {{-- blocks that apply to every shop's mail. --}}
    <div class="pc-legend" style="margin-top:22px">
      <b>Blocked for all shops ({{ $allShops->count() }}).</b> These addresses bounced from three or more shops,
      so no shop's mail reaches them: campaigns, receipts, reminders, everything. Intake's own mail above is not
      affected by this list. Each shop sees these on its own suppression list, marked All shops.
    </div>

    <div class="pc-list">
      @forelse($allShops as $row)
        <div class="pc-row" style="grid-template-columns:1fr 150px 1fr 110px">
          <div>
            <div class="t" style="font-size:13.5px">{{ $row->email }}</div>
            <div class="d">since {{ $row->suppressed_at ? alocal($row->suppressed_at, 'M j, Y') : '—' }}</div>
          </div>
          <div>
            <span class="pc-pill pc-pill--custom">All shops</span>
            <span class="pc-pill">{{ $row->reason === 'complaint' ? 'Marked as spam' : 'Bounced' }}</span>
          </div>
          <div class="fires">{{ \Illuminate\Support\Str::limit($row->notes ?: ($row->diagnostic ?: '—'), 90) }}</div>
          <div style="text-align:right">
            <button type="button" class="pc-edit" wire:click="unblockAllShops({{ (int) $row->id }})">Unblock</button>
          </div>
        </div>
      @empty
        <div style="padding:26px 16px;text-align:center;opacity:.5;font-size:13px">No address is blocked for all shops.</div>
      @endforelse
    </div>
  @endif
</div>
</x-filament-panels::page>
