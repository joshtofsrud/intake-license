@extends('layouts.tenant.app')
@php $pageTitle = 'Communication'; @endphp

{{-- MARKER-PATCH-404 — Communication Center --}}
@push('styles')
<style>
  .cc-wrap{max-width:1000px}
  .cc-head h1{font-size:23px;font-weight:740;letter-spacing:-.02em;margin:0 0 3px;color:var(--ia-text,#f4f4f5)}
  .cc-head p{color:var(--ia-text-2,#a6a6ac);margin:0 0 16px;font-size:13.5px}
  .cc-sender{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--ia-surface,#161619);
    border:1px solid var(--ia-border,#2a2a2e);border-radius:11px;padding:10px 14px;margin-bottom:18px;font-size:12.5px}
  .cc-sender .l{font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-text-3,#74747a);font-weight:600}
  .cc-sender .v{font-family:ui-monospace,Menlo,monospace;color:var(--ia-text,#f4f4f5)}
  .cc-sender .sep{width:1px;height:16px;background:var(--ia-border,#2a2a2e)}
  .cc-sender a{color:var(--ia-accent,#e0a82e);text-decoration:none;font-weight:600;margin-left:auto}

  .cc-flash{background:rgba(63,185,80,.13);border:1px solid rgba(63,185,80,.34);color:#56d364;
    border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;margin-bottom:16px}

  .cc-tabs{display:flex;gap:4px;border-bottom:1px solid var(--ia-border,#2a2a2e);margin-bottom:22px}
  .cc-tab{appearance:none;background:none;border:none;cursor:pointer;font:inherit;padding:10px 15px;
    color:var(--ia-text-3,#74747a);font-size:14px;font-weight:600;border-bottom:2px solid transparent;margin-bottom:-1px}
  .cc-tab:hover{color:var(--ia-text-2,#a6a6ac)}
  .cc-tab.on{color:var(--ia-text,#f4f4f5);border-bottom-color:var(--ia-accent,#e0a82e)}
  .cc-view{display:none}.cc-view.on{display:block}

  .cc-tbl{width:100%;border-collapse:separate;border-spacing:0;background:var(--ia-surface,#161619);
    border:1px solid var(--ia-border,#2a2a2e);border-radius:13px;overflow:hidden}
  .cc-tbl th{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--ia-text-3,#74747a);
    font-weight:600;text-align:left;padding:11px 16px;border-bottom:1px solid var(--ia-border,#2a2a2e);background:rgba(255,255,255,.02)}
  .cc-tbl th.c{text-align:center;width:84px}.cc-tbl th.last{width:64px}
  .cc-tbl td{padding:13px 16px;border-bottom:1px solid var(--ia-border,#22222600);border-bottom-color:rgba(255,255,255,.05);vertical-align:middle}
  .cc-tbl td.c{text-align:center}
  .cc-tbl tr:last-child td{border-bottom:none}
  .cc-grp td{background:rgba(255,255,255,.015);border-bottom:1px solid var(--ia-border,#2a2a2e);
    padding:7px 16px;font-family:ui-monospace,Menlo,monospace;font-size:10px;letter-spacing:.15em;
    text-transform:uppercase;color:var(--ia-text-3,#74747a)}
  .cc-mname{font-weight:640;color:var(--ia-text,#f4f4f5)}
  .cc-mdesc{color:var(--ia-text-3,#74747a);font-size:12px;margin-top:1px}
  .cc-fires{color:var(--ia-text-2,#a6a6ac);font-size:12.5px}
  .cc-dash{color:var(--ia-text-3,#54545c)}
  .cc-always{font-size:11px;font-weight:700;color:#56d364}

  .cc-sw{appearance:none;border:none;cursor:pointer;width:38px;height:22px;border-radius:99px;
    background:#3a3a40;position:relative;transition:background .15s;vertical-align:middle}
  .cc-sw::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;
    background:#d9d9de;transition:left .15s}
  .cc-sw.on{background:var(--ia-accent,#e0a82e)}.cc-sw.on::after{left:19px;background:var(--ia-accent-text,#0a0a0a)}
  .cc-sw.blk{background:#26262a;cursor:not-allowed}.cc-sw.blk::after{background:#4a4a52}
  .cc-blkwrap{display:inline-flex;flex-direction:column;align-items:center;gap:3px}
  .cc-blknote{font-size:9.5px;color:#e3b341;text-decoration:underline;text-underline-offset:2px}
  .cc-edit{font-size:12px;color:var(--ia-accent,#e0a82e);text-decoration:none;font-weight:600;white-space:nowrap}

  .cc-savebar{display:flex;align-items:center;gap:14px;margin-top:16px}
  .cc-dirty{font-size:12.5px;color:var(--ia-text-3,#74747a)}
  .cc-dirty.on{color:#e3b341}
  .cc-save{background:var(--ia-accent,#e0a82e);color:var(--ia-accent-text,#0a0a0a);border:none;border-radius:9px;
    font-weight:700;font-size:13px;padding:10px 18px;cursor:pointer;font:inherit;font-weight:700}
  .cc-hint{display:flex;gap:9px;align-items:flex-start;background:var(--ia-accent-soft,rgba(224,168,46,.13));
    border:1px solid var(--ia-border,#2a2a2e);border-radius:10px;padding:11px 14px;margin-top:18px;
    font-size:12.5px;color:var(--ia-text-2,#a6a6ac)}

  .cc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
  .cc-card{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:13px;padding:17px}
  .cc-card .t{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px}
  .cc-card h3{font-size:14px;margin:0;font-weight:680;color:var(--ia-text,#f4f4f5)}
  .cc-card p{color:var(--ia-text-2,#a6a6ac);font-size:12.5px;margin:0 0 12px}
  .cc-card .meta{font-family:ui-monospace,Menlo,monospace;font-size:10.5px;color:var(--ia-text-3,#74747a);
    border-top:1px solid rgba(255,255,255,.05);padding-top:10px;margin-bottom:11px}
  .cc-cta{display:inline-block;background:var(--ia-accent,#e0a82e);color:var(--ia-accent-text,#0a0a0a);
    font-weight:700;font-size:12.5px;padding:8px 13px;border-radius:8px;text-decoration:none}
  .cc-cta.ghost{background:transparent;color:var(--ia-accent,#e0a82e);border:1px solid var(--ia-accent,#e0a82e)}

  .b{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:7px;font-size:11px;
    font-weight:700;border:1px solid transparent;white-space:nowrap}
  .b .d{width:6px;height:6px;border-radius:50%}
  .b.live{color:#56d364;background:rgba(63,185,80,.13);border-color:rgba(63,185,80,.34)}.b.live .d{background:#56d364}
  .b.warn{color:#e3b341;background:rgba(210,153,34,.13);border-color:rgba(210,153,34,.34)}.b.warn .d{background:#e3b341}
  .b.bad{color:#ff7b72;background:rgba(248,81,73,.13);border-color:rgba(248,81,73,.34)}.b.bad .d{background:#ff7b72}
  .b.off{color:var(--ia-text-3,#74747a);background:transparent;border-color:var(--ia-border,#2a2a2e)}

  .cc-log{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:13px;overflow:hidden}
  .cc-lr{display:grid;grid-template-columns:104px 1fr 170px 96px;gap:14px;align-items:center;padding:11px 16px;
    border-bottom:1px solid rgba(255,255,255,.05);font-size:12.5px}
  .cc-lr:last-child{border-bottom:none}
  .cc-lr .ev{font-weight:600;color:var(--ia-text,#f4f4f5)}
  .cc-lr .ch{font-family:ui-monospace,Menlo,monospace;font-size:9.5px;color:var(--ia-text-3,#74747a);margin-left:8px}
  .cc-lr .to{color:var(--ia-text-2,#a6a6ac);font-family:ui-monospace,Menlo,monospace;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cc-lr .tm{color:var(--ia-text-3,#54545c);font-family:ui-monospace,Menlo,monospace;font-size:11px;text-align:right}
  .cc-empty{padding:40px;text-align:center;color:var(--ia-text-3,#74747a);font-size:13px}

  @media(max-width:760px){.cc-grid{grid-template-columns:1fr}.cc-lr{grid-template-columns:1fr;gap:3px}.cc-lr .tm{text-align:left}}

  /* MARKER-PATCH-405 — editor drawer */
  .cc-bd{position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;transition:opacity .2s;z-index:60}
  .cc-bd.show{opacity:1;pointer-events:auto}
  .cc-drawer{position:fixed;top:0;right:0;height:100vh;width:min(820px,96vw);background:var(--ia-bg,#0b0b0c);
    border-left:1px solid var(--ia-border,#2a2a2e);transform:translateX(100%);transition:transform .24s cubic-bezier(.2,.7,.2,1);
    z-index:70;display:flex;flex-direction:column}
  .cc-drawer.show{transform:translateX(0)}
  .cc-dh{padding:17px 22px;border-bottom:1px solid var(--ia-border,#2a2a2e);display:flex;align-items:center;justify-content:space-between;gap:12px}
  .cc-dh h2{font-size:17px;margin:0;font-weight:700;color:var(--ia-text,#f4f4f5)}
  .cc-dh .ds{color:var(--ia-text-3,#74747a);font-size:12px;margin-top:2px}
  .cc-x{appearance:none;background:none;border:none;color:var(--ia-text-3,#74747a);cursor:pointer;font-size:22px;line-height:1;padding:4px}
  .cc-x:hover{color:var(--ia-text,#f4f4f5)}
  .cc-dbody{padding:22px;overflow-y:auto;flex:1}
  .cc-dgrid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
  @media(max-width:760px){.cc-dgrid{grid-template-columns:1fr}}
  .cc-fld{margin-bottom:16px}
  .cc-fld label{display:block;font-size:12px;font-weight:650;margin-bottom:6px;color:var(--ia-text,#f4f4f5)}
  .cc-help{font-size:11.5px;color:var(--ia-text-3,#74747a);margin:-2px 0 8px}
  .cc-inp{width:100%;background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:9px;
    color:var(--ia-text,#f4f4f5);font:inherit;font-size:13px;padding:10px 12px}
  .cc-inp:disabled{opacity:.5}
  textarea.cc-ta{min-height:150px;resize:vertical;line-height:1.55}
  .cc-vars{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
  .cc-var{font-family:ui-monospace,Menlo,monospace;font-size:11px;background:var(--ia-surface,#1b1b1f);
    border:1px solid var(--ia-border,#2a2a2e);border-radius:6px;padding:3px 8px;color:var(--ia-text-2,#a6a6ac);cursor:pointer}
  .cc-var:hover{border-color:var(--ia-accent,#e0a82e);color:var(--ia-text,#f4f4f5)}
  .cc-locked{background:var(--ia-accent-soft,rgba(224,168,46,.13));border:1px solid var(--ia-border,#2a2a2e);
    border-radius:9px;padding:10px 12px;font-size:11.5px;color:var(--ia-text-2,#a6a6ac)}
  .cc-locked b{color:var(--ia-accent,#e0a82e)}
  .cc-prevlab{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-text-3,#74747a);font-weight:600;margin-bottom:9px}
  .cc-email{background:#fff;color:#1a1a1a;border-radius:10px;overflow:hidden;border:1px solid var(--ia-border,#2a2a2e);font-size:12px}
  .cc-ehead{background:#0e0e10;padding:13px 16px}
  .cc-ehead .lg{font-weight:800;color:#fff;font-size:13px;letter-spacing:.02em}
  .cc-ebody{padding:17px 16px}
  .cc-greet{color:#333;line-height:1.55;white-space:pre-wrap;margin-bottom:14px}
  .cc-greet.receipt{background:#fff7e6;border-left:3px solid #e0a82e;padding:8px 10px}
  .cc-pitems .li{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #eee;color:#444}
  .cc-pitems .tot{display:flex;justify-content:space-between;padding:9px 0 0;font-weight:700;color:#111;margin-top:4px}
  .cc-efoot{padding:11px 16px;background:#f5f5f5;color:#888;font-size:10.5px;text-align:center}
  .cc-prevnote{color:var(--ia-text-3,#74747a);font-size:11px;margin-top:9px}
  .cc-dfoot{padding:15px 22px;border-top:1px solid var(--ia-border,#2a2a2e);display:flex;gap:10px;align-items:center}
  .cc-ghost{background:none;border:1px solid var(--ia-border,#2a2a2e);color:var(--ia-text-2,#a6a6ac);border-radius:9px;font:inherit;font-size:13px;padding:10px 16px;cursor:pointer}
  .cc-dfoot .right{margin-left:auto}
</style>
@endpush

@section('content')
<div class="cc-wrap">
  <div class="cc-head">
    <h1>Communication</h1>
    <p>One place for every message your shop sends and receives.</p>
  </div>

  <div class="cc-sender">
    <span class="l">Sending as</span>
    <span class="v">{{ $fromName }} · {{ $fromEmail }}</span>
    <span class="sep"></span>
    <span class="l">Replies</span>
    <span class="v">{{ $replyTo ?: $fromEmail }}</span>
    <a href="{{ route('tenant.settings.index') }}">Edit sender details →</a>
  </div>

  @if(session('success'))<div class="cc-flash">{{ session('success') }}</div>@endif

  <div class="cc-tabs">
    <button class="cc-tab on" data-tab="messages" type="button">Messages</button>
    <button class="cc-tab" data-tab="inbound" type="button">Inbound</button>
    <button class="cc-tab" data-tab="activity" type="button">Activity</button>
  </div>

  {{-- ================= MESSAGES ================= --}}
  <div class="cc-view on" id="cc-messages">
    <form method="POST" action="{{ route('tenant.communication.toggles') }}">
      @csrf
      @method('PATCH')
      <table class="cc-tbl">
        <thead>
          <tr>
            <th>Message</th>
            <th class="c">Email</th>
            <th class="c">Text</th>
            <th>Fires when</th>
            <th class="last"></th>
          </tr>
        </thead>
        <tbody>
          @php $lastGroup = null; @endphp
          @foreach($catalog as $m)
            @if($m['group'] !== $lastGroup)
              <tr class="cc-grp"><td colspan="5">{{ $m['group'] }}</td></tr>
              @php $lastGroup = $m['group']; @endphp
            @endif
            <tr>
              <td>
                <div class="cc-mname">{{ $m['label'] }}</div>
                <div class="cc-mdesc">{{ $m['desc'] }}</div>
              </td>

              {{-- email --}}
              <td class="c">
                @php $eid = 'sw_'.$m['key'].'_email'; $eon = $m['state']['email'] ?? false; @endphp
                <input type="hidden" name="notify_{{ $m['key'] }}_email" id="{{ $eid }}" value="{{ $eon ? 1 : 0 }}">
                <button type="button" class="cc-sw {{ $eon ? 'on' : '' }}" data-for="{{ $eid }}" onclick="ccTog(this)" aria-label="Toggle email"></button>
              </td>

              {{-- sms --}}
              <td class="c">
                @if(in_array('sms', $m['channels']))
                  @php $sid = 'sw_'.$m['key'].'_sms'; $son = $m['state']['sms'] ?? false; @endphp
                  @if($smsReady)
                    <input type="hidden" name="notify_{{ $m['key'] }}_sms" id="{{ $sid }}" value="{{ $son ? 1 : 0 }}">
                    <button type="button" class="cc-sw {{ $son ? 'on' : '' }}" data-for="{{ $sid }}" onclick="ccTog(this)" aria-label="Toggle text"></button>
                  @else
                    <span class="cc-blkwrap">
                      <button type="button" class="cc-sw blk" onclick="ccShake(this)" aria-label="Texting not set up"></button>
                      <a class="cc-blknote" href="{{ route('tenant.settings.messaging') }}">Set up texting</a>
                    </span>
                  @endif
                @else
                  <span class="cc-dash">—</span>
                @endif
              </td>

              <td class="cc-fires">{{ $m['fires'] }}</td>
              <td><button type="button" class="cc-edit" onclick="ccOpenEd(this)"
                    data-type="{{ $m['template'] }}"
                    data-editor="{{ $m['editor'] }}"
                    data-label="{{ $m['label'] }}"
                    data-desc="{{ $m['desc'] }}"
                    data-sms="{{ in_array('sms', $m['channels']) ? 1 : 0 }}"
                    data-vars="{{ implode(',', $m['vars']) }}"
                    data-subject="{{ $m['subject'] }}"
                    data-defsubject="{{ $m['def_subject'] }}"
                    data-body="{{ $m['body'] }}"
                    data-defbody="{{ $m['def_body'] }}">Edit</button></td>
            </tr>
          @endforeach

          <tr class="cc-grp"><td colspan="5">System</td></tr>
          <tr>
            <td><div class="cc-mname">Password reset</div><div class="cc-mdesc">Staff requests a reset</div></td>
            <td class="c"><span class="cc-always">Always</span></td>
            <td class="c"><span class="cc-dash">—</span></td>
            <td class="cc-fires">Staff clicks “forgot password”</td>
            <td><a class="cc-edit" href="{{ route('tenant.emails.index') }}">Edit</a></td>
          </tr>
        </tbody>
      </table>

      <div class="cc-savebar">
        <button type="submit" class="cc-save">Save changes</button>
        <span class="cc-dirty" id="ccDirty">All changes saved</span>
      </div>
    </form>

    <div class="cc-hint">
      <span>💡</span>
      <span>Receipts are <b>on</b> and reaching customers. The lifecycle messages above are off — for a mobile service, <b>Delivery scheduled</b> and the reminders are usually the ones worth turning on first.</span>
    </div>
  </div>

  {{-- ================= INBOUND ================= --}}
  <div class="cc-view" id="cc-inbound">
    <div class="cc-grid">
      <div class="cc-card">
        <div class="t"><h3>Text messaging</h3>
          @if($smsReady)<span class="b live"><span class="d"></span>Active</span>@else<span class="b bad"><span class="d"></span>Not set up</span>@endif
        </div>
        @if($smsReady)
          <p>Customers can text {{ $smsNumber }} and replies land in your Inbox.</p>
          <div class="meta">two-way · STOP/START handled · per-customer threads</div>
          <a class="cc-cta ghost" href="{{ route('tenant.settings.messaging') }}">Manage number</a>
        @else
          <p>Get a business number so customers can text you — and so the Text switches start working. Replies land in your Inbox.</p>
          <div class="meta">two-way · STOP/START handled · per-customer threads</div>
          <a class="cc-cta" href="{{ route('tenant.settings.messaging') }}">Set up texting</a>
        @endif
      </div>

      <div class="cc-card">
        <div class="t"><h3>Email replies</h3><span class="b warn"><span class="d"></span>Needs setup</span></div>
        <p>When a customer replies to any email, it lands back in the same Inbox thread. Quoted history is stripped automatically.</p>
        <div class="meta">built · set POSTMARK_INBOUND_ADDRESS + Postmark inbound webhook</div>
      </div>

      <div class="cc-card">
        <div class="t"><h3>Bounce handling</h3><span class="b warn"><span class="d"></span>Off</span></div>
        <p>Auto-remove dead addresses so you stop emailing them and protect your sending reputation.</p>
        <div class="meta">built · enable Bounce + SpamComplaint webhooks in Postmark</div>
        <a class="cc-cta ghost" href="{{ route('tenant.suppressions.index') }}">View suppressions</a>
      </div>
    </div>
  </div>

  {{-- ================= ACTIVITY ================= --}}
  <div class="cc-view" id="cc-activity">
    @if($logs->isEmpty())
      <div class="cc-log"><div class="cc-empty">No messages sent yet. Once a receipt or notification goes out, it shows up here.</div></div>
    @else
      <div class="cc-log">
        @foreach($logs as $log)
          @php
            $st = $log->status;
            $cls = $st === 'sent' ? 'live' : ($st === 'failed' ? 'bad' : 'off');
            $lbl = $st === 'sent' ? 'Sent' : ucfirst($st);
            $ev  = ucwords(str_replace('_', ' ', $log->event_type));
          @endphp
          <div class="cc-lr">
            <span class="b {{ $cls }}"><span class="d"></span>{{ $lbl }}</span>
            <span class="ev">{{ $ev }}<span class="ch">{{ strtoupper($log->channel) }}</span></span>
            <span class="to">{{ $log->recipient }}</span>
            <span class="tm">{{ optional($log->created_at)->format('M j g:ia') }}</span>
          </div>
        @endforeach
      </div>
      <p style="color:var(--ia-text-3,#74747a);font-size:12px;margin-top:11px">Shows what the app tried to send and the outcome. Delivery and bounce status come from Postmark.</p>
    @endif
  </div>

</div>

{{-- MARKER-PATCH-405 — editor drawer --}}
<div class="cc-bd" id="ccBackdrop" onclick="ccCloseEd()"></div>
<aside class="cc-drawer" id="ccDrawer" aria-hidden="true">
  <div class="cc-dh">
    <div><h2 id="ccEdTitle">Edit message</h2><div class="ds" id="ccEdSub"></div></div>
    <button class="cc-x" type="button" onclick="ccCloseEd()">×</button>
  </div>
  <form method="POST" id="ccEdForm" action="">
    @csrf
    @method('PATCH')
    <input type="hidden" name="op" value="save">
    <div class="cc-dbody">
      <div class="cc-dgrid">
        <div>
          <div class="cc-fld">
            <label>Subject line</label>
            <input class="cc-inp" name="subject" id="ccEdSubject" autocomplete="off">
          </div>
          <div class="cc-fld">
            <label id="ccEdBodyLabel">Email body</label>
            <div class="cc-help" id="ccEdBodyHelp"></div>
            <textarea class="cc-inp cc-ta" name="body" id="ccEdBody" oninput="ccPrev()"></textarea>
            <div class="cc-vars" id="ccEdVars"></div>
          </div>
          <div class="cc-locked" id="ccEdLocked" style="display:none"><b>Built-in:</b> the header, line-item table, totals, and footer come from the receipt layout — that is why a receipt looks complete even with the greeting blank.</div>
          <div class="cc-fld" id="ccEdSmsWrap" style="display:none">
            <label>Text version</label>
            <div class="cc-help">Generated automatically for now — editable once texting is set up.</div>
            <textarea class="cc-inp" rows="2" disabled>@{{shop_name}}: details below. Reply STOP to opt out.</textarea>
          </div>
        </div>
        <div>
          <div class="cc-prevlab">Preview</div>
          <div class="cc-email">
            <div class="cc-ehead"><span class="lg">{{ $fromName }}</span></div>
            <div class="cc-ebody">
              <div class="cc-greet" id="ccPrevGreet"></div>
              <div class="cc-pitems" id="ccPrevItems" style="display:none">
                <div class="li"><span>Tune-up — full service</span><span>$95.00</span></div>
                <div class="li"><span>Brake pads (pair)</span><span>$38.00</span></div>
                <div class="li"><span>Shop labor</span><span>$25.00</span></div>
                <div class="tot"><span>Total</span><span>$158.00</span></div>
              </div>
            </div>
            <div class="cc-efoot" id="ccPrevFoot">{{ $fromName }}</div>
          </div>
          <p class="cc-prevnote" id="ccPrevNote"></p>
        </div>
      </div>
    </div>
    <div class="cc-dfoot">
      <button type="submit" class="cc-save">Save changes</button>
      <button type="submit" name="op" value="reset" class="cc-ghost" onclick="return confirm('Reset this message to the built-in default? Your custom copy will be removed.')">Reset to default</button>
      <span class="right cc-help" id="ccEdStatus"></span>
    </div>
  </form>
</aside>
@endsection

@push('scripts')
<script>
  (function(){
    document.querySelectorAll('.cc-tab').forEach(function(t){
      t.addEventListener('click', function(){
        document.querySelectorAll('.cc-tab').forEach(x=>x.classList.remove('on'));
        document.querySelectorAll('.cc-view').forEach(x=>x.classList.remove('on'));
        t.classList.add('on');
        document.getElementById('cc-' + t.dataset.tab).classList.add('on');
      });
    });
  })();
  function ccTog(btn){
    btn.classList.toggle('on');
    var inp = document.getElementById(btn.dataset.for);
    if(inp){ inp.value = btn.classList.contains('on') ? 1 : 0; }
    var d = document.getElementById('ccDirty');
    if(d){ d.textContent = 'Unsaved changes'; d.classList.add('on'); }
  }
  function ccShake(btn){
    btn.animate([{transform:'translateX(0)'},{transform:'translateX(-3px)'},{transform:'translateX(3px)'},{transform:'translateX(0)'}],{duration:200});
  }

  // MARKER-PATCH-405 — editor drawer
  var ccTplUrl = "{{ route('tenant.communication.template', ['type' => '__TYPE__']) }}";
  var ccLastField = null;
  var ccIsReceipt = false;
  function ccInsert(el, text){
    var s = el.selectionStart, e = el.selectionEnd;
    el.value = el.value.slice(0, s) + text + el.value.slice(e);
    el.selectionStart = el.selectionEnd = s + text.length;
    el.focus();
  }
  function ccPrev(){
    var body = document.getElementById('ccEdBody');
    var txt = (body.value && body.value.length) ? body.value : (body.placeholder || '');
    var g = document.getElementById('ccPrevGreet');
    g.textContent = txt;
    g.className = ccIsReceipt ? 'cc-greet receipt' : 'cc-greet';
  }
  function ccOpenEd(btn){
    var d = btn.dataset;
    ccIsReceipt = d.editor === 'receipt';
    document.getElementById('ccEdForm').action = ccTplUrl.replace('__TYPE__', d.type);
    document.getElementById('ccEdTitle').textContent = d.label;
    document.getElementById('ccEdSub').textContent   = d.desc || '';
    var subj = document.getElementById('ccEdSubject');
    subj.value = d.subject || ''; subj.placeholder = d.defsubject || '';
    var body = document.getElementById('ccEdBody');
    body.value = d.body || ''; body.placeholder = d.defbody || '';
    subj.onfocus = function(){ ccLastField = subj; };
    body.onfocus = function(){ ccLastField = body; };
    ccLastField = body; // default chip target until a field is focused
    document.getElementById('ccEdBodyLabel').textContent = ccIsReceipt ? 'Greeting' : 'Email body';
    document.getElementById('ccEdBodyHelp').textContent = ccIsReceipt
      ? 'One line above the receipt. The itemized layout, totals, and footer are built in automatically — you do not write those.'
      : 'This whole message is yours — there is no built-in layout underneath.';
    document.getElementById('ccEdLocked').style.display = ccIsReceipt ? '' : 'none';
    document.getElementById('ccEdSmsWrap').style.display = (d.sms === '1') ? '' : 'none';
    document.getElementById('ccPrevItems').style.display = ccIsReceipt ? '' : 'none';
    document.getElementById('ccPrevNote').textContent = ccIsReceipt
      ? 'Everything except your greeting is automatic layout.'
      : 'No built-in layout — what you type is the email.';
    var vbox = document.getElementById('ccEdVars'); vbox.innerHTML = '';
    var ccB1 = '{' + '{', ccB2 = '}' + '}';
    (d.vars || '').split(',').filter(Boolean).forEach(function(v){
      var s = document.createElement('span'); s.className = 'cc-var'; s.textContent = ccB1 + v + ccB2;
      s.onclick = function(){ ccInsert(ccLastField || body, ccB1 + v + ccB2); ccPrev(); };
      vbox.appendChild(s);
    });
    ccPrev();
    document.getElementById('ccBackdrop').classList.add('show');
    document.getElementById('ccDrawer').classList.add('show');
  }
  function ccCloseEd(){
    document.getElementById('ccBackdrop').classList.remove('show');
    document.getElementById('ccDrawer').classList.remove('show');
  }
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape') ccCloseEd(); });
</script>
@endpush
