@extends('layouts.tenant.app')
@php $pageTitle = "What's New"; @endphp

@push('styles')
<style>
.wn-page { max-width: 820px; }
.wn-hero { padding: 24px 0 32px; border-bottom: 0.5px solid var(--ia-border); margin-bottom: 32px; }
.wn-hero-title { font-size: 24px; font-weight: 700; margin-bottom: 6px; }
.wn-hero-sub { font-size: 14px; opacity: .55; }

.wn-entry { display: grid; grid-template-columns: 110px 1fr; gap: 24px; padding: 20px 0; border-bottom: 0.5px solid var(--ia-border); }
.wn-entry:last-child { border-bottom: none; }
.wn-entry.is-highlighted {
  background: linear-gradient(to right, var(--ia-accent-soft), transparent 50%);
  border-radius: var(--ia-r-lg);
  padding: 20px;
  margin: 0 -20px;
}
.wn-date { color: var(--ia-text); opacity: .55; font-size: 12px; font-weight: 500; padding-top: 4px; }
.wn-meta { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 6px; }
.wn-cat { display: inline-block; padding: 2px 8px; background: rgba(255,255,255,.05); border-radius: 4px; font-size: 11px; font-weight: 500; }
.wn-pin { font-size: 10px; color: var(--ia-accent); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.wn-title { font-size: 16px; font-weight: 600; margin-bottom: 6px; line-height: 1.35; }
.wn-body { font-size: 14px; line-height: 1.6; opacity: .8; white-space: pre-line; }

.wn-empty { text-align: center; padding: 64px 0; opacity: .5; font-size: 14px; }

@media (max-width: 640px) {
  .wn-entry { grid-template-columns: 1fr; gap: 4px; padding: 16px 0; }
  .wn-date { padding-top: 0; }
}
</style>
@endpush

@section('content')

<div class="wn-page">
  <div class="wn-hero">
    <h1 class="wn-hero-title">What's New</h1>
    <p class="wn-hero-sub">Recent improvements to Intake. Newest first.</p>
  </div>

  @forelse($entries as $entry)
    <article class="wn-entry @if($entry->is_highlighted) is-highlighted @endif">
      <div class="wn-date">{{ $entry->shipped_on->format('M j, Y') }}</div>
      <div>
        <div class="wn-meta">
          @if($entry->category)
            <span class="wn-cat">{{ $entry->category }}</span>
          @endif
          @if($entry->is_highlighted)
            <span class="wn-pin">Featured</span>
          @endif
        </div>
        <h2 class="wn-title">{{ $entry->title }}</h2>
        <div class="wn-body">{{ $entry->body }}</div>
      </div>
    </article>
  @empty
    <div class="wn-empty">No updates published yet.</div>
  @endforelse
</div>

@endsection
