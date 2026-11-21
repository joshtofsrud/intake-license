<x-filament-panels::page>
<style>
  .sx-root { max-width:680px; font-size:14px; }
  .sx-stat { display:flex; gap:28px; margin-bottom:26px; }
  .sx-stat b { display:block; font-size:24px; font-weight:650; font-variant-numeric:tabular-nums; }
  .sx-stat span { font-size:12.5px; color:#a3a3ab; }
  .sx-row { display:flex; gap:14px; align-items:flex-start; padding:16px 0; border-top:1px solid rgba(255,255,255,.1); }
  .sx-row input { margin-top:3px; width:16px; height:16px; accent-color:#8b5cf6; }
  .sx-row b { font-weight:600; } .sx-row small { display:block; font-size:12.5px; color:#a3a3ab; line-height:1.55; margin-top:3px; }
  .sx-row code { font-size:12px; background:rgba(255,255,255,.07); padding:1px 6px; border-radius:5px; user-select:all; }
  .sx-pill { font-size:11px; font-weight:600; padding:2px 8px; border-radius:99px; margin-left:8px; }
  .sx-pill.ok { background:rgba(190,242,100,.16); color:#BEF264; } .sx-pill.no { background:rgba(244,124,124,.16); color:#f47c7c; }
  .sx-btn { border:1px solid #8b5cf6; background:#8b5cf6; color:#fff; border-radius:7px; padding:7px 13px; font-weight:500; font-size:13px; cursor:pointer; margin-top:18px; }
  details.sx-how { font-size:12.5px; color:#a3a3ab; margin-top:8px; } details.sx-how li { margin:3px 0; }
</style>
<div class="sx-root">
  <div class="sx-stat">
    <div><b>{{ number_format($this->shopsChecked()) }}</b><span>shops with a site check</span></div>
    <div><b>{{ number_format($this->shopsConnected()) }}</b><span>shops with Google connected</span></div>
  </div>

  <label class="sx-row">
    <input type="checkbox" wire:model="google" @disabled(! $this->configured())>
    <span>
      <b>Shops can connect Google</b>
      <span class="sx-pill {{ $this->configured() ? 'ok' : 'no' }}">{{ $this->configured() ? 'Google client is set' : 'No Google client on the server' }}</span>
      <small>Search Console, Analytics and Merchant Center, read only. Off hides every Google panel from shops; the site check itself is always on.</small>
      <details class="sx-how"><summary>What Google Cloud needs first</summary>
        <ol>
          <li>Add this redirect address to the OAuth client: <code>{{ $this->callbackUrl() }}</code></li>
          <li>Turn on: Search Console API, Analytics Data API, Analytics Admin API, Merchant API.</li>
          <li>Add the scopes to the consent screen. Until Google verifies the app, only its listed test users can connect.</li>
        </ol>
      </details>
    </span>
  </label>

  <label class="sx-row">
    <input type="checkbox" wire:model="reviews" @disabled(! $this->configured())>
    <span>
      <b>Reviews</b>
      <small>Rating, reviews and replies from each shop's Business Profile. Google approves access to this API on request, so leave it off until that approval arrives.</small>
      <details class="sx-how"><summary>What Google Cloud needs first</summary>
        <ol>
          <li>Apply for Business Profile API access for the project, and wait for approval.</li>
          <li>Turn on: My Business Account Management API, My Business Business Information API, Google My Business API.</li>
          <li>Shops that connected before this was on connect again to add reviews.</li>
        </ol>
      </details>
    </span>
  </label>

  <button class="sx-btn" wire:click="save" wire:loading.attr="disabled">Save</button>
</div>
</x-filament-panels::page>
