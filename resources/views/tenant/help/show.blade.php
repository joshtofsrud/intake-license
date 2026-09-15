@extends('layouts.tenant.app')

{{-- MARKER-HELP-TENANT — an article body is page-builder sections, rendered
     through the SAME public partials the builder writes, inside a wrapper that
     carries the platform tenant's design tokens. That reuse is the reason an
     article is a page rather than a second content system. --}}
@section('content')
@php
  $dt = \App\Support\DesignTokens::resolve($platform);
@endphp

<style>
  .hlp-art{max-width:820px}
  .hlp-back{display:inline-block;color:var(--ia-text-3,#888);text-decoration:none;font-size:13px;margin-bottom:14px}
  .hlp-back:hover{color:inherit}
  .hlp-art h1{font-size:26px;font-weight:800;letter-spacing:-0.025em;margin:0 0 18px}
  .hlp-body{
{!! \App\Support\DesignTokens::cssVars($dt, '    ') !!}
  }
  .hlp-body section{padding-left:0 !important;padding-right:0 !important}
  .hlp-locked-box{border:1px solid rgba(190,242,100,.35);background:rgba(190,242,100,.08);border-radius:14px;padding:20px 22px}
  .hlp-locked-box h2{font-size:15px;font-weight:700;margin:0 0 6px}
  .hlp-locked-box p{margin:0 0 4px;font-size:13.5px;color:var(--ia-text-2,#aaa);line-height:1.6}
  .hlp-rel{border-top:1px solid var(--ia-border,#1f1f1f);margin-top:28px;padding-top:16px}
  .hlp-rel h2{font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-3,#888);font-weight:700;margin:0 0 8px}
  .hlp-rel a{display:flex;align-items:center;gap:10px;padding:11px 0;color:inherit;text-decoration:none;
    border-bottom:1px solid var(--ia-border,#1f1f1f);font-size:13.5px}
  .hlp-rel a:last-child{border-bottom:0}
  .hlp-rel a span{margin-left:auto;color:var(--ia-text-3,#888)}
</style>

<div class="hlp-art">
  <a class="hlp-back" href="{{ route('tenant.help.index') }}#guides">← All guides</a>
  <h1>{{ $article->title }}</h1>

  @if($locked)
    <div class="hlp-locked-box">
      <h2>This one covers {{ $missing->pluck('name')->join(' and ') }}</h2>
      <p>Your shop doesn't have {{ $missing->count() === 1 ? 'that add-on' : 'those add-ons' }} turned on, so the steps wouldn't match what you see on screen.</p>
      @foreach($missing as $addon)
        <p><strong>{{ $addon->name }}</strong>@if($addon->description) — {{ $addon->description }}@endif</p>
      @endforeach
    </div>
  @else
    <div class="hlp-body">
      @forelse($sections as $section)
        @php
          $type    = $section->section_type;
          $allowed = in_array($type, \App\Http\Controllers\Tenant\HelpController::DOC_SECTIONS, true);
          $partial = 'public.sections._' . $type;
          $sc      = $section->content ?? [];
          $sc['bg_color'] = \App\Support\DesignTokens::sectionBg($sc['bg_color'] ?? null, $type, $dt);
        @endphp
        @if($allowed && view()->exists($partial))
          @include($partial, [
            'c'        => $sc,
            'section'  => $section,
            'navItems' => [],
            'catalog'  => null,
            'tenant'   => $platform,
          ])
        @elseif(config('app.debug'))
          <div style="padding:14px;margin:12px 0;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;color:#78350f;font-family:monospace;font-size:12.5px;">
            Section type <code>{{ $type }}</code> is not rendered in guides — doc-safe types only.
          </div>
        @endif
      @empty
        <p style="color:var(--ia-text-3,#888);font-size:13.5px;">This guide doesn't have any content yet.</p>
      @endforelse
    </div>
  @endif

  @if($related->count())
    <div class="hlp-rel">
      <h2>Next</h2>
      @foreach($related as $r)
        <a href="{{ route('tenant.help.article', $r->slug) }}">{{ $r->title }}<span>›</span></a>
      @endforeach
    </div>
  @endif
</div>
@endsection
