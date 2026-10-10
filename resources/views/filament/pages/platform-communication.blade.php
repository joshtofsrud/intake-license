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
  .pc-btn:disabled{opacity:.4;cursor:not-allowed}
  .pl-file{position:relative;overflow:hidden;display:inline-block}
  .pl-file input{position:absolute;inset:0;opacity:0;cursor:pointer}
  .pl-seg{display:inline-flex;border:1px solid var(--pc-line);border-radius:8px;padding:2px}
  .pl-seg button{background:none;border:0;color:inherit;opacity:.6;padding:5px 11px;border-radius:6px;font:inherit;font-size:12.5px;cursor:pointer}
  .pl-seg button.on{opacity:1;background:rgba(255,255,255,.08);font-weight:600}
  .pl-blk{border:1px solid var(--pc-line);border-radius:10px;padding:10px 12px;margin-bottom:10px}
  .pl-blk-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px}
  .pl-blk-h button{background:none;border:0;color:inherit;opacity:.6;cursor:pointer;font-size:13px;padding:0 4px}
  .pl-blk textarea,.pl-blk input[type=text]{width:100%;background:transparent;border:1px solid var(--pc-line);border-radius:8px;color:inherit;font:inherit;font-size:13.5px;padding:8px 10px}
  .pl-grid{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:6px}
  .pl-grid .pl-h{font-size:12px;opacity:.75}
  .pl-add{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0 14px;font-size:12.5px;opacity:.9}
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

    @if($campaign)
      <div class="pc-cols">
        <div class="pc-card">
          <h3>{{ $campaign->name }}</h3>
          <div class="sub">{{ ucfirst($campaign->status) }}@if($campaign->scheduled_at) · {{ $campaign->scheduled_at->format('D M j, g:i A') }}@endif</div>

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
          {{-- plain text, or a letter built from blocks --}}
          <div class="pc-f">
            <label>Content</label>
            <div class="pl-seg">
              <button type="button" class="{{ $cMode === 'body' ? 'on' : '' }}" wire:click="useBody">Plain text</button>
              <button type="button" class="{{ $cMode === 'letter' ? 'on' : '' }}" wire:click="useLetter">Letter</button>
            </div>
          </div>
          @if($cMode === 'letter')
            <div class="pc-note" style="margin:-4px 0 10px">
              Tokens: <code>{first_name}</code> <code>{shop_name}</code> — and for investors <code>{amount}</code> <code>{percent}</code> <code>{remaining}</code> <code>{portal}</code>.
              The preview on the right uses sample values.
            </div>
            @foreach($cBlocks as $i => $blk)
              <div class="pl-blk" wire:key="lb-{{ $i }}-{{ $blk['type'] }}">
                <div class="pl-blk-h">
                  <b>{{ $letterTypes[$blk['type']] ?? $blk['type'] }}</b>
                  <span>
                    <button type="button" wire:click="moveLetterBlock({{ $i }}, -1)" title="Up">↑</button>
                    <button type="button" wire:click="moveLetterBlock({{ $i }}, 1)" title="Down">↓</button>
                    <button type="button" wire:click="removeLetterBlock({{ $i }})" title="Remove">✕</button>
                  </span>
                </div>
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
                @endswitch
              </div>
            @endforeach
            <div class="pl-add">
              <span>Add:</span>
              @foreach($letterTypes as $lt => $ll)
                <button type="button" class="pc-btn" wire:click="addLetterBlock('{{ $lt }}')">{{ $ll }}</button>
              @endforeach
            </div>
          @else
          <div class="pc-f">
            <label>Body</label>
            <textarea wire:model="cBody" placeholder="Write the email. Blank lines make paragraphs."></textarea>
            <div class="pc-tok"><code>&#123;&#123;first_name&#125;&#125;</code><code>&#123;&#123;shop_name&#125;&#125;</code></div>
          </div>
          @endif
          <div class="pc-f">
            <label>Send at <span style="opacity:.55">(leave blank to send now)</span></label>
            <input type="datetime-local" wire:model="cSchedule">
          </div>

          @if($blockers)
            <div class="pc-note" style="color:#f0c46a">
              @foreach($blockers as $b){{ $b }} @endforeach
            </div>
          @endif

          <div class="pc-acts">
            <button type="button" class="pc-btn" wire:click="saveCampaign">Save draft</button>
            <button type="button" class="pc-btn pc-btn--pri" wire:click="scheduleCampaign" @disabled(count($blockers) > 0)>
              {{ trim($cSchedule) !== '' ? 'Schedule' : 'Send now' }}
            </button>
            @if(in_array($campaign->status, ['scheduled', 'sending'], true))
              <button type="button" class="pc-btn pc-btn--warn" wire:click="cancelCampaign">Cancel</button>
            @endif
            <button type="button" class="pc-btn" wire:click="closeCampaign">Close</button>
          </div>
        </div>

        <div class="pc-card">
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
              <div class="pc-note">Sample values stand in for the tokens. The email itself goes out in Light; Dark shows roughly how a dark-mode inbox will treat it.</div>
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

      <div class="pc-list">
        @forelse($campaigns as $c)
          <div class="pc-row" style="grid-template-columns:1fr 110px 1fr 150px">
            <div>
              <div class="t">{{ $c->name }}</div>
              <div class="d">{{ $c->subject ?: 'No subject yet' }}</div>
            </div>
            <div><span class="pc-pill {{ $c->status === 'sent' ? 'pc-pill--custom' : '' }}">{{ ucfirst($c->status) }}</span></div>
            <div class="fires">
              {{ optional($c->audience)->name ?: 'No audience' }}
              @if($c->total_sent) · {{ $c->total_sent }} sent @endif
            </div>
            <div class="pc-acts-row">
              @if($confirmDel === 'c:' . $c->id)
                <span style="opacity:.7">Delete?</span>
                <button type="button" class="pc-del" wire:click="deleteCampaign('{{ $c->id }}')">Yes</button>
                <button type="button" class="pc-edit" wire:click="cancelDelete">No</button>
              @else
                @if($c->status === 'draft')<button type="button" class="pc-del" wire:click="askDelete('c:{{ $c->id }}')">Delete</button>@endif
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
              @if($aSource === 'prospects')
                <div class="ab-gate"><b>Only prospects who asked to hear from us.</b> Campaigns send through Postmark's broadcast stream, which only allows people who opted in, so a prospect is included once they booked a call, wrote in through the site or became a tenant. @if($abP && $abP['cold'] > 0){{ number_format($abP['cold']) }} cold prospects are never included; they're contacted one by one, not by campaign.@endif</div>
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
              <div class="pc-grp">First {{ count($abP['sample']) }}</div>
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
            <div class="d">since {{ $row->suppressed_at?->format('M j, Y') ?? '—' }}</div>
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
