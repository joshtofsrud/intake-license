@extends('layouts.tenant.app')
@php $pageTitle = 'Pages'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Pages</h1>
    <p class="ia-page-subtitle">Build your public-facing website.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ tenant_url() }}" target="_blank" class="ia-btn ia-btn--secondary">
      View site →
    </a>
    <button type="button" class="ia-btn ia-btn--primary"
      onclick="document.getElementById('new-page-form').style.display='block';this.style.display='none'">
      + New page
    </button>
  </div>
</div>

<div id="new-page-form" class="ia-card ia-card--tight" style="display:none;margin-bottom:20px">
  <form method="POST" action="{{ route('tenant.pages.store') }}" style="display:flex;gap:10px;align-items:flex-end">
    @csrf
    <div class="ia-form-group" style="flex:1;margin-bottom:0">
      <label class="ia-form-label">Page title</label>
      <input type="text" name="title" class="ia-input" placeholder="e.g. About us" required autofocus>
    </div>
    <button type="submit" class="ia-btn ia-btn--primary">Create page</button>
    <button type="button" class="ia-btn ia-btn--ghost"
      onclick="document.getElementById('new-page-form').style.display='none';document.querySelector('.ia-btn--primary').style.display=''">
      Cancel
    </button>
  </form>
</div>

<div class="ia-table-wrap">
  <table class="ia-table">
    <thead>
      <tr>
        <th>Page</th>
        <th>URL</th>
        <th>Status</th>
        <th>In nav</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach($pages as $page)
        <tr>
          <td>
            <div style="font-weight:500">{{ $page->title }}</div>
            @if($page->is_home)
              <div style="font-size:11px;opacity:.4">Home page</div>
            @endif
          </td>
          <td class="ia-muted-cell">
            {{ $page->is_home ? '/' : '/' . $page->slug }}
          </td>
          <td>
            @if($page->is_published)
              <span class="ia-badge ia-badge--completed">Published</span>
            @else
              <span class="ia-badge ia-badge--pending">Draft</span>
            @endif
          </td>
          <td>
            <span style="font-size:13px;opacity:.5">{{ $page->is_in_nav ? 'Yes' : 'No' }}</span>
          </td>
          <td style="text-align:right;white-space:nowrap">
            {{-- MARKER-PAGE-PUBLISH — flip status without opening the editor. --}}
            <form method="POST" action="{{ route('tenant.pages.update', $page->id) }}" style="display:inline">
              @csrf @method('PATCH')
              <input type="hidden" name="op" value="set_published">
              <input type="hidden" name="is_published" value="{{ $page->is_published ? 0 : 1 }}">
              <button class="ia-btn ia-btn--sm {{ $page->is_published ? '' : 'ia-btn--primary' }}"
                      @if($page->is_published) data-confirm="Unpublish '{{ $page->title }}'? Visitors will no longer be able to reach it." @endif>
                {{ $page->is_published ? 'Unpublish' : 'Publish' }}
              </button>
            </form>
            <a href="{{ route('tenant.pages.index', ['edit' => $page->id]) }}"
               class="ia-btn ia-btn--secondary ia-btn--sm">Edit</a>
            @if(!$page->is_home && $page->slug !== 'book')
              <form method="POST" action="{{ route('tenant.pages.store', ['delete' => $page->id]) }}"
                style="display:inline" data-confirm="Delete '{{ $page->title }}'?">
                @csrf
                <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Delete</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>

{{-- MARKER-WELCOME / MARKER-WELCOME-POLISH — the site-wide holding page.
     Structure mirrors the splash card below (.ia-card + .ia-card-head) so
     the two line up; the first pass rolled its own padding and didn't. --}}
@php $wAllowable = \App\Support\WelcomePage::ALLOWABLE; @endphp
<div class="ia-card wl-card {{ $welcome['enabled'] ? 'is-on' : '' }}">
  <form method="POST" action="{{ route('tenant.pages.welcome') }}">
    @csrf
    <div class="ia-card-head wl-head">
      <div>
        <div class="ia-card-title">
          Welcome page
          @if($welcome['enabled'])<span class="wl-pill">On</span>@endif
        </div>
        <div class="wl-sub">Show a holding page instead of your site, while you get things ready.</div>
      </div>
      <label class="wl-switch" title="Turn the welcome page on or off">
        <input type="checkbox" name="welcome_enabled" value="1" @checked($welcome['enabled'])>
        <span class="wl-track"></span>
      </label>
    </div>

    <div class="wl-grid">
      {{-- fields --}}
      <div class="wl-fields">
        <label class="wl-label" for="wl-headline">Headline</label>
        <input type="text" id="wl-headline" name="welcome_headline" maxlength="120" class="ia-input"
               value="{{ $welcome['headline'] }}" placeholder="Something good is coming.">

        <label class="wl-label" for="wl-message">Message</label>
        <textarea id="wl-message" name="welcome_message" rows="3" maxlength="400" class="ia-input"
                  placeholder="We're putting the finishing touches on the new site.">{{ $welcome['message'] }}</textarea>

        <label class="wl-label">Button</label>
        <div class="wl-row">
          <input type="text" id="wl-cta" name="welcome_cta_label" maxlength="40" class="ia-input"
                 value="{{ $welcome['cta_label'] }}" placeholder="Call the shop">
          <input type="text" name="welcome_cta_url" maxlength="255" class="ia-input"
                 value="{{ $welcome['cta_url'] }}" placeholder="tel:5095550142">
        </div>

        <label class="wl-label">Let these through anyway</label>
        <div class="wl-allow">
          @foreach($wAllowable as $key => $meta)
            <label class="wl-check">
              <input type="checkbox" name="welcome_allow[]" value="{{ $key }}"
                     @checked(in_array($key, $welcome['allow'], true))>
              <span>{{ $meta['label'] }}</span>
              <code>{{ $meta['path'] }}</code>
            </label>
          @endforeach
        </div>

        <div class="wl-note">
          <b>You'll still see the real site.</b> Signed-in staff bypass the welcome
          page, so you can keep working on it while visitors see the holding page.
        </div>
      </div>

      {{-- live preview: repaints as you type, so the copy is judged in
           place rather than in another tab --}}
      <div class="wl-side">
        <div class="wl-label">Preview</div>
        <div class="wl-prev">
          <div class="wl-prev-bar">{{ parse_url($currentTenant->publicUrl(), PHP_URL_HOST) }}</div>
          <div class="wl-prev-body">
            @if($currentTenant->logo_url)
              <img class="wl-prev-logo" src="{{ $currentTenant->logo_url }}" alt="">
            @else
              <div class="wl-prev-mark">{{ strtoupper(substr($currentTenant->name, 0, 2)) }}</div>
            @endif
            <div class="wl-prev-h" data-wl-preview="headline">{{ $welcome['headline'] }}</div>
            <div class="wl-prev-p" data-wl-preview="message">{{ $welcome['message'] }}</div>
            <div class="wl-prev-cta" data-wl-preview="cta" @if(!$welcome['cta_label']) hidden @endif>{{ $welcome['cta_label'] }}</div>
            <div class="wl-prev-meta">{{ $currentTenant->name }}</div>
          </div>
        </div>
        <div class="wl-hint">Uses your logo, colours and contact details automatically — nothing else to fill in.</div>
      </div>
    </div>

    <div class="wl-foot">
      <a href="{{ route('tenant.pages.welcome.preview') }}" target="_blank" rel="noopener"
         class="ia-btn ia-btn--secondary ia-btn--sm">Open full page ↗</a>
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save welcome page</button>
    </div>
  </form>
</div>

@push('scripts')
<script>
// MARKER-WELCOME-POLISH — live preview. Cheap enough to run on input; no
// fetch, no debounce needed.
(function () {
  var map = {
    'wl-headline': ['headline', 'Something good is coming.'],
    'wl-message':  ['message',  ''],
    'wl-cta':      ['cta',      ''],
  };
  Object.keys(map).forEach(function (id) {
    var input = document.getElementById(id);
    var target = document.querySelector('[data-wl-preview="' + map[id][0] + '"]');
    if (!input || !target) return;
    input.addEventListener('input', function () {
      var v = input.value.trim();
      target.textContent = v || map[id][1];
      // An empty message or button shouldn't leave a gap in the preview.
      if (map[id][0] !== 'headline') target.hidden = (v === '');
    });
    if (map[id][0] !== 'headline' && !input.value.trim()) target.hidden = true;
  });
})();
</script>
@endpush

{{-- MARKER-SPLASH-2-UI — pairing table. Each row is a sentence: when
     someone visits THIS page, show them THAT splash. --}}
@php
  $splashablePages = $pages->where('is_published', true);
@endphp

<div class="ia-card sp-card">
  <div class="ia-card-head sp-head">
    <div>
      <div class="ia-card-title">Splash pages</div>
      <div class="sp-sub">A splash appears before a page loads. Pages without a row here are never interrupted.@if($welcome['enabled'])<br><span style="color:#FBBF24">The welcome page is on, so visitors never reach these.</span>@endif</div>
    </div>
    <label class="sp-switch" title="Turn every splash on or off">
      <input type="checkbox" id="sp-enabled" form="sp-form" name="splash_enabled" value="1" @checked($splashEnabled)>
      <span class="sp-track"></span>
    </label>
  </div>

  <form method="POST" action="{{ route('tenant.pages.splash.save') }}" id="sp-form">
    @csrf
    <input type="hidden" name="splash_enabled" value="0" form="sp-form" id="sp-enabled-off">

    <div class="sp-split">
      {{-- ---------------- rows + settings ---------------- --}}
      <div class="sp-pane-l" id="sp-pane">
        <div class="sp-rows">
          <div class="sp-rhead">
            <div>When someone visits</div>
            <div>Show them this splash</div>
            <div></div>
          </div>
          <div id="sp-rowlist"></div>
          <button type="button" class="sp-add" id="sp-add">+ Add a splash for another page</button>
        </div>

        <div class="sp-settings" id="sp-settings"></div>
      </div>

      {{-- ---------------- live preview ---------------- --}}
      <div class="sp-pane-r">
        <div class="sp-prevbar">
          <span class="sp-prevlbl">Live preview</span>
          <div class="sp-seg" id="sp-dev">
            <button type="button" class="on" data-d="desktop">Desktop</button>
            <button type="button" data-d="mobile">Mobile</button>
          </div>
        </div>
        <div class="sp-frame" id="sp-frame">
          <div class="sp-chrome"><i></i><i></i><i></i><span class="sp-url" id="sp-url"></span></div>
          <iframe id="sp-iframe" title="Splash preview" src="about:blank"></iframe>
          <div class="sp-frame-empty" id="sp-frame-empty">
            Add a row to see what your visitors will get.
          </div>
        </div>
        <div class="sp-cap" id="sp-cap"></div>
      </div>
    </div>

    <div class="sp-foot">
      <button type="submit" class="ia-btn ia-btn--primary">Save</button>
      <button type="button" class="ia-btn ia-btn--secondary" id="sp-reload">Reload preview</button>
      <span class="sp-status" id="sp-status"></span>
    </div>
  </form>
</div>

@push('styles')
<style>
  .sp-card{margin-top:22px}
  .sp-head{display:flex;align-items:center;gap:14px}
  .sp-sub{font-size:12px;color:var(--ia-text-dim);margin-top:3px;line-height:1.45}
  .sp-switch{margin-left:auto;position:relative;width:44px;height:25px;flex:0 0 44px;cursor:pointer}
  .sp-switch input{position:absolute;opacity:0;width:0;height:0}
  .sp-track{position:absolute;inset:0;border-radius:99px;background:rgba(127,127,127,.3);transition:background .16s}
  .sp-track::after{content:'';position:absolute;top:3px;left:3px;width:19px;height:19px;border-radius:50%;background:#fff;transition:transform .16s}
  .sp-switch input:checked + .sp-track{background:var(--ia-accent)}
  .sp-switch input:checked + .sp-track::after{transform:translateX(19px)}
  .sp-switch input:focus-visible + .sp-track{outline:2px solid var(--ia-accent);outline-offset:2px}

  .sp-split{display:grid;grid-template-columns:1fr 1fr;min-height:540px}
  @media (max-width:1040px){.sp-split{grid-template-columns:1fr}}
  .sp-pane-l{padding:18px 20px;border-right:.5px solid var(--ia-border)}
  .sp-pane-r{padding:18px 20px;background:rgba(127,127,127,.04);display:flex;flex-direction:column;min-width:0}
  .sp-pane-l.is-off{opacity:.4;pointer-events:none}

  .sp-rows{border:.5px solid var(--ia-border);border-radius:11px;overflow:hidden;margin-bottom:14px}
  .sp-rhead{display:grid;grid-template-columns:1fr 1fr 30px;gap:10px;padding:9px 13px;
    background:rgba(127,127,127,.07);border-bottom:.5px solid var(--ia-border);
    font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--ia-text-dim);font-weight:700}
  .sp-row{display:grid;grid-template-columns:1fr 1fr 30px;gap:10px;align-items:center;
    padding:12px 13px;border-bottom:.5px solid rgba(127,127,127,.14);cursor:pointer;width:100%;
    background:transparent;border-left:0;border-right:0;border-top:0;text-align:left;font:inherit;color:var(--ia-text)}
  .sp-row:last-of-type{border-bottom:0}
  .sp-row.sel{background:rgba(224,164,88,.08);box-shadow:inset 3px 0 0 var(--ia-accent)}
  .sp-row .v{font-size:13.5px;font-weight:600}
  .sp-row .p{font-size:11px;color:var(--ia-text-dim);font-family:var(--ia-font-mono);margin-top:2px}
  .sp-row .s{font-size:13.5px}
  .sp-row .m{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .sp-x{background:transparent;border:0;color:var(--ia-text-dim);font-size:16px;line-height:1;
    padding:5px;border-radius:6px;cursor:pointer}
  .sp-x:hover{background:rgba(127,127,127,.14);color:var(--ia-text)}
  .sp-add{width:100%;padding:11px;background:transparent;border:0;border-top:.5px solid var(--ia-border);
    color:var(--ia-accent);font:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
  .sp-add:hover{background:rgba(224,164,88,.06)}
  .sp-empty{padding:26px 18px;text-align:center;font-size:12.5px;color:var(--ia-text-dim);line-height:1.6}

  .sp-settings{border:.5px solid var(--ia-border);border-radius:11px;padding:15px}
  .sp-settings h4{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--ia-text-dim);
    font-weight:700;margin:0 0 13px}
  .sp-field{margin-bottom:16px}
  .sp-field:last-child{margin-bottom:0}
  .sp-field > label{display:block;font-size:12.5px;font-weight:600;margin-bottom:7px}
  .sp-hint{font-size:11.5px;color:var(--ia-text-dim);line-height:1.55;margin-top:7px;max-width:430px}
  .sp-settings select,.sp-settings input[type=date]{max-width:320px}
  .sp-tiles{display:grid;grid-template-columns:1fr 1fr;gap:9px;max-width:430px}
  .sp-tile{text-align:left;background:rgba(127,127,127,.06);border:1px solid var(--ia-border);
    border-radius:10px;padding:12px;cursor:pointer;font:inherit;color:var(--ia-text)}
  .sp-tile.on{border-color:var(--ia-accent);background:rgba(224,164,88,.08)}
  .sp-tile b{display:block;font-size:12.5px;margin-bottom:3px}
  .sp-tile span{font-size:11px;color:var(--ia-text-dim);line-height:1.45;display:block}
  .sp-note{margin-top:9px;padding:9px 11px;border-radius:9px;font-size:11.5px;line-height:1.55;max-width:430px;
    background:rgba(190,242,100,.07);border:.5px solid rgba(190,242,100,.26);color:#8DBF3F}
  .ia-theme-c .sp-note{color:#BEF264}
  .sp-note.warn{background:rgba(245,197,107,.09);border-color:rgba(245,197,107,.32);color:#B07A1E}
  .ia-theme-c .sp-note.warn{color:#F5C56B}
  .sp-dates{display:flex;gap:9px;align-items:center;max-width:430px}
  .sp-dates input{max-width:155px}
  .sp-dates span{font-size:12px;color:var(--ia-text-dim)}

  .sp-prevbar{display:flex;align-items:center;gap:8px;margin-bottom:11px}
  .sp-prevlbl{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--ia-text-dim);font-weight:700}
  .sp-seg{display:flex;gap:4px;margin-left:auto}
  .sp-seg button{padding:6px 11px;background:transparent;border:.5px solid var(--ia-border);
    border-radius:7px;color:var(--ia-text-dim);font:inherit;font-size:11.5px;cursor:pointer}
  .sp-seg button.on{background:rgba(127,127,127,.12);color:var(--ia-text)}
  .sp-frame{flex:1;min-height:430px;border:.5px solid var(--ia-border);border-radius:10px;overflow:hidden;
    background:var(--ia-surface);display:flex;flex-direction:column;position:relative}
  .sp-frame.mobile{max-width:400px;width:100%;margin:0 auto}
  .sp-chrome{display:flex;align-items:center;gap:6px;padding:7px 10px;background:rgba(127,127,127,.1);flex:0 0 auto}
  .sp-chrome i{width:7px;height:7px;border-radius:50%;background:rgba(127,127,127,.35)}
  .sp-url{margin-left:5px;font-size:10.5px;color:var(--ia-text-dim);font-family:var(--ia-font-mono)}
  .sp-frame iframe{flex:1;width:100%;border:0;background:#fff}
  .sp-frame-empty{position:absolute;inset:34px 0 0;display:flex;align-items:center;justify-content:center;
    text-align:center;padding:24px;font-size:12.5px;color:var(--ia-text-dim);line-height:1.6;
    background:var(--ia-surface)}
  .sp-cap{font-size:11.5px;color:var(--ia-text-dim);line-height:1.55;margin-top:10px}

  .sp-foot{display:flex;align-items:center;gap:10px;padding:14px 20px;border-top:.5px solid var(--ia-border)}
  .sp-status{font-size:12px;color:var(--ia-text-dim);margin-left:auto}

/* MARKER-WELCOME / MARKER-WELCOME-POLISH — inherits .ia-card padding so it
   sits on the same rhythm as the splash card below. */
.wl-card{margin-top:22px}
.wl-card.is-on{box-shadow:inset 3px 0 0 #FBBF24}
.wl-head{align-items:flex-start;gap:14px}
.wl-pill{font-size:9.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
  background:rgba(251,191,36,.16);color:#FBBF24;border-radius:99px;padding:2px 8px;margin-left:8px}
.wl-sub{font-size:12px;color:var(--ia-text-dim);margin-top:3px;line-height:1.45}
.wl-switch{margin-left:auto;position:relative;width:44px;height:25px;flex:0 0 44px;cursor:pointer}
.wl-switch input{position:absolute;opacity:0;width:0;height:0}
.wl-track{position:absolute;inset:0;border-radius:99px;background:rgba(127,127,127,.3);transition:background .16s}
.wl-track::after{content:'';position:absolute;top:3px;left:3px;width:19px;height:19px;
  border-radius:50%;background:#fff;transition:transform .16s}
.wl-switch input:checked + .wl-track{background:#FBBF24}
.wl-switch input:checked + .wl-track::after{transform:translateX(19px)}
.wl-switch input:focus-visible + .wl-track{outline:2px solid var(--ia-accent);outline-offset:2px}

.wl-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:24px;align-items:start}
.wl-label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;
  color:var(--ia-text-muted);font-weight:700;margin:0 0 6px}
.wl-fields .wl-label:not(:first-child){margin-top:14px}
.wl-fields .ia-input{width:100%}
.wl-row{display:flex;gap:8px}
.wl-row .ia-input{flex:1;min-width:0}
.wl-allow{display:flex;flex-direction:column;gap:8px}
.wl-check{display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer}
.wl-check code{font-size:11px;color:var(--ia-text-muted)}
.wl-note{border:1px solid rgba(251,191,36,.35);background:rgba(251,191,36,.06);
  border-radius:8px;padding:10px 12px;font-size:11.5px;line-height:1.6;
  color:var(--ia-text-dim);margin-top:14px}
.wl-note b{color:var(--ia-text)}

/* Preview */
.wl-prev{border:.5px solid var(--ia-border);border-radius:10px;overflow:hidden;background:#0d0d0d}
.wl-prev-bar{background:rgba(255,255,255,.04);border-bottom:.5px solid var(--ia-border);
  padding:6px 10px;font-size:10.5px;color:var(--ia-text-muted);
  font-family:ui-monospace,Menlo,monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wl-prev-body{padding:26px 18px;text-align:center;
  background:radial-gradient(circle at 50% 0%, color-mix(in srgb, var(--ia-accent) 12%, transparent), transparent 65%)}
.wl-prev-logo{height:26px;margin:0 auto 12px;display:block;object-fit:contain}
.wl-prev-mark{width:34px;height:34px;border-radius:9px;margin:0 auto 12px;
  display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;
  background:color-mix(in srgb, var(--ia-accent) 16%, transparent);color:var(--ia-accent)}
.wl-prev-h{font-size:16px;font-weight:700;letter-spacing:-.01em;line-height:1.25;color:#f2f2f2}
.wl-prev-p{font-size:11.5px;color:rgba(255,255,255,.5);line-height:1.55;margin-top:7px}
.wl-prev-cta{display:inline-block;margin-top:14px;padding:7px 16px;border-radius:7px;
  background:var(--ia-accent);color:var(--ia-accent-text,#0a0a0a);font-size:11.5px;font-weight:700}
.wl-prev-meta{font-size:10.5px;color:rgba(255,255,255,.3);margin-top:14px}
.wl-hint{font-size:11px;color:var(--ia-text-muted);line-height:1.55;margin-top:9px}

.wl-foot{display:flex;justify-content:flex-end;gap:8px;
  margin-top:20px;padding-top:16px;border-top:.5px solid var(--ia-border)}
@media (max-width:900px){ .wl-grid{grid-template-columns:1fr} .wl-side{order:-1} }
</style>
@endpush

@push('scripts')
@php
  // MARKER-SPLASH-2-JSONFIX — computed here so @json() receives a single
  // variable. A multi-line array literal inside a Blade directive's
  // parentheses cannot be parsed and fatals the whole view.
  $spPagesJs = $splashablePages->map(function ($p) {
      return [
          'id'    => $p->id,
          'title' => $p->title,
          'path'  => $p->is_home ? '/' : '/' . $p->slug,
      ];
  })->values();

  $spRowsJs = $splashRows->map(function ($p) {
      return [
          'visit_page_id'  => $p->id,
          'splash_page_id' => $p->splash_page_id,
          'mode'           => $p->splash_mode ?: 'overlay',
          'style'          => $p->splash_style ?: 'full',
          'frequency'      => (string) ($p->splash_frequency ?: 'session'),
          'starts_at'      => $p->splash_starts_at ? $p->splash_starts_at->format('Y-m-d') : '',
          'ends_at'        => $p->splash_ends_at ? $p->splash_ends_at->format('Y-m-d') : '',
      ];
  })->values();

  $spPreviewUrl = route('tenant.pages.preview', ['id' => '__ID__']);
@endphp
<script>
(function () {
  // MARKER-SPLASH-2-UI — the table is edited client-side and submitted as
  // rows[]; the server replaces the whole set, so a removed row really is
  // removed.
  var PAGES = @json($spPagesJs);
  var rows  = @json($spRowsJs);

  var PREVIEW = @json($spPreviewUrl);
  var sel = rows.length ? 0 : -1;

  var $ = function (id) { return document.getElementById(id); };
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); }
  function pageById(id){ for (var i=0;i<PAGES.length;i++) if (PAGES[i].id===id) return PAGES[i]; return null; }
  function title(id){ var p = pageById(id); return p ? p.title : '(deleted page)'; }
  function path(id){ var p = pageById(id); return p ? p.path : ''; }
  function fmtDate(d){
    if (!d) return '';
    var parts = d.split('-');
    var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][+parts[1]-1];
    return m + ' ' + (+parts[2]);
  }

  function drawRows() {
    var el = $('sp-rowlist');
    if (!rows.length) {
      el.innerHTML = '<div class="sp-empty">No splashes yet.<br>' +
        'Add one to put an announcement, an event, or a notice in front of a page.</div>';
      $('sp-settings').innerHTML = '<div class="sp-empty" style="padding:14px">Add a row above to configure it.</div>';
      return;
    }
    el.innerHTML = rows.map(function (r, i) {
      var win = (r.starts_at || r.ends_at)
        ? (fmtDate(r.starts_at) || 'Now') + ' \u2013 ' + (fmtDate(r.ends_at) || 'no end')
        : 'Always on';
      return '<button type="button" class="sp-row ' + (i === sel ? 'sel' : '') + '" data-i="' + i + '">' +
        '<span><span class="v">' + esc(title(r.visit_page_id)) + '</span>' +
        '<span class="p">' + esc(path(r.visit_page_id)) + '</span></span>' +
        '<span><span class="s">' + esc(title(r.splash_page_id)) + '</span>' +
        '<span class="m">' + (r.mode === 'overlay' ? 'Overlay' : 'Separate page') + ' \u00b7 ' + win + '</span></span>' +
        '<span class="sp-x" role="button" aria-label="Remove" data-del="' + i + '">\u00d7</span>' +
      '</button>';
    }).join('');

    el.querySelectorAll('.sp-row').forEach(function (n) {
      n.addEventListener('click', function (e) {
        if (e.target.hasAttribute('data-del')) {
          rows.splice(+e.target.getAttribute('data-del'), 1);
          if (sel >= rows.length) sel = rows.length - 1;
        } else {
          sel = +n.getAttribute('data-i');
        }
        drawRows(); drawSettings(); paint(); sync();
      });
    });
  }

  function opts(selectedId) {
    return PAGES.map(function (p) {
      return '<option value="' + esc(p.id) + '"' + (p.id === selectedId ? ' selected' : '') + '>' +
        esc(p.title) + '</option>';
    }).join('');
  }

  function drawSettings() {
    if (!rows.length) return;
    var r = rows[sel];
    $('sp-settings').innerHTML =
      '<h4>Editing: before ' + esc(title(r.visit_page_id)) + '</h4>' +

      '<div class="sp-field"><label for="sp-visit">When someone visits</label>' +
        '<select id="sp-visit" class="ia-input">' + opts(r.visit_page_id) + '</select>' +
        '<div class="sp-hint">This includes people arriving straight from a link you shared, so only add pages you really want to interrupt.</div>' +
      '</div>' +

      '<div class="sp-field"><label for="sp-splash">Show them this splash</label>' +
        '<select id="sp-splash" class="ia-input">' + opts(r.splash_page_id) + '</select>' +
        '<div class="sp-hint">Any published page can be a splash. It stays out of your navigation while it is used as one.</div>' +
      '</div>' +

      '<div class="sp-field"><label>How it appears</label>' +
        '<div class="sp-tiles" id="sp-mode">' +
          '<button type="button" class="sp-tile ' + (r.mode === 'overlay' ? 'on' : '') + '" data-v="overlay">' +
            '<b>Overlay</b><span>Sits on top. The page still loads underneath.</span></button>' +
          '<button type="button" class="sp-tile ' + (r.mode === 'page' ? 'on' : '') + '" data-v="page">' +
            '<b>Separate page</b><span>Its own URL. The page waits until they click through.</span></button>' +
        '</div>' +
        (r.mode === 'overlay'
          ? '<div class="sp-note">Google still reads the real page, and it works without JavaScript.</div>'
          : '<div class="sp-note warn">Google will index the splash instead of this page &mdash; which can cost you traffic.</div>') +
      '</div>' +

      '<div class="sp-field"><label>Style</label>' +
        '<div class="sp-tiles" id="sp-style">' +
          '<button type="button" class="sp-tile ' + (r.style === 'full' ? 'on' : '') + '" data-v="full">' +
            '<b>Full screen</b><span>Covers everything.</span></button>' +
          '<button type="button" class="sp-tile ' + (r.style === 'sheet' ? 'on' : '') + '" data-v="sheet">' +
            '<b>Bottom sheet</b><span>Slides up from the bottom.</span></button>' +
        '</div>' +
      '</div>' +

      '<div class="sp-field"><label for="sp-freq">Show it</label>' +
        '<select id="sp-freq" class="ia-input">' +
          '<option value="session"' + (r.frequency==='session'?' selected':'') + '>Once per visit</option>' +
          '<option value="7"' + (r.frequency==='7'?' selected':'') + '>Once every 7 days</option>' +
          '<option value="30"' + (r.frequency==='30'?' selected':'') + '>Once every 30 days</option>' +
          '<option value="always"' + (r.frequency==='always'?' selected':'') + '>Every page load</option>' +
        '</select>' +
        '<div class="sp-hint">Counted separately for each splash, so dismissing one does not hide another. &ldquo;Every page load&rdquo; shows it again and again, including to your regulars.</div>' +
      '</div>' +

      '<div class="sp-field"><label>Only between these dates <span style="font-weight:400;color:var(--ia-text-dim)">(optional)</span></label>' +
        '<div class="sp-dates">' +
          '<input type="date" id="sp-from" class="ia-input" value="' + esc(r.starts_at) + '">' +
          '<span>to</span>' +
          '<input type="date" id="sp-to" class="ia-input" value="' + esc(r.ends_at) + '">' +
        '</div>' +
        '<div class="sp-hint">Leave empty to show it until you turn it off. With dates set it stops on its own &mdash; nobody has to remember.</div>' +
      '</div>';

    $('sp-visit').addEventListener('change', function(){ r.visit_page_id = this.value; drawRows(); drawSettings(); paint(); sync(); });
    $('sp-splash').addEventListener('change', function(){ r.splash_page_id = this.value; drawRows(); paint(); sync(); });
    $('sp-freq').addEventListener('change', function(){ r.frequency = this.value; sync(); });
    $('sp-from').addEventListener('change', function(){ r.starts_at = this.value; drawRows(); sync(); });
    $('sp-to').addEventListener('change', function(){ r.ends_at = this.value; drawRows(); sync(); });
    document.querySelectorAll('#sp-mode .sp-tile').forEach(function (t) {
      t.addEventListener('click', function(){ r.mode = t.getAttribute('data-v'); drawRows(); drawSettings(); paint(); sync(); });
    });
    document.querySelectorAll('#sp-style .sp-tile').forEach(function (t) {
      t.addEventListener('click', function(){ r.style = t.getAttribute('data-v'); drawSettings(); paint(); sync(); });
    });
  }

  function paint() {
    var frame = $('sp-frame-empty'), iframe = $('sp-iframe');
    if (!rows.length || sel < 0) {
      frame.style.display = 'flex'; iframe.src = 'about:blank';
      $('sp-url').textContent = ''; $('sp-cap').textContent = '';
      return;
    }
    frame.style.display = 'none';
    var r = rows[sel];
    // The preview renders the VISITED page with its splash composited on
    // top — the same thing the server does for a real visitor.
    iframe.src = PREVIEW.replace('__ID__', encodeURIComponent(r.visit_page_id)) + '?over=1&t=' + Date.now();

    var base = path(r.visit_page_id) || '/';
    $('sp-url').textContent = (r.mode === 'page')
      ? (path(r.splash_page_id) || '/')
      : base;
    $('sp-cap').textContent = (r.mode === 'overlay')
      ? 'Someone opening ' + base + ' sees this. The page itself is loaded behind it.'
      : 'Someone opening ' + base + ' is sent to the splash first; ' + base + ' is not served until they continue.';
  }

  // Keep hidden inputs in step with the table, so the form posts what you see.
  function sync() {
    var form = $('sp-form');
    form.querySelectorAll('[data-sp-row]').forEach(function (n) { n.remove(); });
    rows.forEach(function (r, i) {
      Object.keys(r).forEach(function (k) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'rows[' + i + '][' + k + ']';
        input.value = r[k] == null ? '' : r[k];
        input.setAttribute('data-sp-row', '1');
        form.appendChild(input);
      });
    });
    $('sp-status').textContent = $('sp-enabled').checked
      ? rows.length + (rows.length === 1 ? ' splash active' : ' splashes active')
      : 'All splashes off';
  }

  $('sp-add').addEventListener('click', function () {
    if (PAGES.length < 2) { alert('You need at least two published pages: one to visit, one to show.'); return; }
    var used = rows.map(function (r) { return r.visit_page_id; });
    var visit = (PAGES.find(function (p) { return used.indexOf(p.id) === -1; }) || PAGES[0]).id;
    var splash = (PAGES.find(function (p) { return p.id !== visit; }) || PAGES[0]).id;
    rows.push({ visit_page_id: visit, splash_page_id: splash, mode: 'overlay',
                style: 'full', frequency: 'session', starts_at: '', ends_at: '' });
    sel = rows.length - 1;
    drawRows(); drawSettings(); paint(); sync();
  });

  $('sp-enabled').addEventListener('change', function () {
    $('sp-pane').classList.toggle('is-off', !this.checked);
    sync();
  });
  document.querySelectorAll('#sp-dev button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('#sp-dev button').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      $('sp-frame').classList.toggle('mobile', b.getAttribute('data-d') === 'mobile');
    });
  });
  $('sp-reload').addEventListener('click', paint);

  $('sp-pane').classList.toggle('is-off', !$('sp-enabled').checked);
  drawRows(); drawSettings(); paint(); sync();
})();
</script>
@endpush

@endsection

