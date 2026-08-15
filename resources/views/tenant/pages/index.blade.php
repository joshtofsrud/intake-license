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

{{-- MARKER-SPLASH --}}
@php
  $splashPageId = optional($pages->firstWhere('is_splash', true))->id;
@endphp
<div class="ia-card" style="margin-top:22px;max-width:760px">
  <div class="ia-card-head">
    <div class="ia-card-title">Splash page</div>
  </div>
  <div style="padding:16px">
    <p style="font-size:12.5px;color:var(--ia-text-dim);margin:0 0 16px;line-height:1.6">
      Shows before your homepage. Build it from sections like any other page.
      Visitors arriving on a direct link &mdash; a shop or booking URL you shared &mdash;
      are never interrupted.
    </p>

    <form method="POST" action="{{ route('tenant.pages.splash.save') }}">
      @csrf

      <label style="display:flex;align-items:center;gap:9px;font-size:13px;margin-bottom:16px">
        <input type="hidden" name="splash_enabled" value="0">
        <input type="checkbox" name="splash_enabled" value="1" @checked($splashCfg['enabled'])>
        Enable splash page
      </label>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:14px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">Which page</label>
        <div>
          <select name="splash_page_id" class="ia-input" style="max-width:320px">
            <option value="">&mdash; none selected &mdash;</option>
            @foreach($pages->where('is_home', false) as $p)
              <option value="{{ $p->id }}" @selected($splashPageId === $p->id)>
                {{ $p->title }}{{ $p->is_published ? '' : ' (draft)' }}
              </option>
            @endforeach
          </select>
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5">
            It must be published to appear, and it will be removed from your navigation.
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:14px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">How it appears</label>
        <div>
          <select name="splash_mode" class="ia-input" style="max-width:320px">
            <option value="overlay" @selected($splashCfg['mode'] === 'overlay')>Overlay &mdash; on top of your homepage</option>
            <option value="page" @selected($splashCfg['mode'] === 'page')>Separate page &mdash; before your homepage</option>
          </select>
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.55">
            <strong>Overlay</strong> keeps your homepage in place underneath, so Google still
            reads your real content and the page works without JavaScript.
            <strong>Separate page</strong> is a firmer gate, but search engines will index the
            splash instead of your homepage &mdash; which can cost you traffic.
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:14px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">Show it</label>
        <div>
          <select name="splash_frequency" class="ia-input" style="max-width:320px">
            <option value="session" @selected($splashCfg['frequency'] === 'session')>Once per visit</option>
            <option value="7"       @selected($splashCfg['frequency'] === '7')>Once every 7 days</option>
            <option value="30"      @selected($splashCfg['frequency'] === '30')>Once every 30 days</option>
            <option value="always"  @selected($splashCfg['frequency'] === 'always')>Every page load</option>
          </select>
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5">
            &ldquo;Every page load&rdquo; shows it to the same person again and again, including your regulars.
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:18px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">Style</label>
        <select name="splash_style" class="ia-input" style="max-width:320px">
          <option value="full"  @selected($splashCfg['style'] === 'full')>Full screen</option>
          <option value="sheet" @selected($splashCfg['style'] === 'sheet')>Bottom sheet</option>
        </select>
      </div>

      <button type="submit" class="ia-btn ia-btn--primary">Save splash settings</button>
    </form>
  </div>
</div>

@endsection

