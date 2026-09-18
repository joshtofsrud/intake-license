<x-filament-panels::page>
{{-- MARKER-PLATFORM-TEMPLATES --}}
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
  .pc-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px}
  .pc-btn{border:1px solid var(--pc-line);background:none;color:inherit;font:inherit;font-size:13px;
    padding:7px 13px;border-radius:9px;cursor:pointer}
  .pc-btn--pri{background:var(--pc-accent);border-color:var(--pc-accent);color:#14121f;font-weight:600}
  .pc-btn--warn{color:var(--pc-warn);border-color:rgba(240,196,106,.4)}
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
    <span class="on">Messages</span>
  </div>

  <div class="pc-legend">
    <b>These are the emails Intake sends about itself</b> — not a shop's mail to its customers, which each shop
    controls on its own Communication page. Edit one and your version sends from then on; Revert deletes it and
    the built-in email comes back.
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
  @endif
</div>
</x-filament-panels::page>
