@extends('marketing.layout')

@section('title', 'Changelog — Intake')
@section('meta_description', 'A running log of what we shipped, by date. Built one session at a time.')

@push('styles')
<style>
.mk-cl-hero { padding: clamp(48px, 7vw, 88px) 0 clamp(24px, 4vw, 40px); text-align: center; border-bottom: 0.5px solid var(--mk-border); }
.mk-cl-eyebrow { display: inline-block; padding: 4px 10px; background: var(--mk-accent-dim); color: var(--mk-accent); border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 16px; }
.mk-cl-title { font-size: clamp(36px, 5vw, 56px); font-weight: 800; letter-spacing: -.02em; margin-bottom: 12px; }
.mk-cl-sub { color: var(--mk-muted); font-size: clamp(16px, 1.6vw, 18px); max-width: 560px; margin: 0 auto; }

.mk-cl-list { padding: clamp(40px, 6vw, 72px) 0; }
.mk-cl-empty { text-align: center; padding: 48px 0; color: var(--mk-muted); font-size: 14px; }

.mk-cl-entry { display: grid; grid-template-columns: 140px 1fr; gap: clamp(16px, 3vw, 32px); padding: clamp(20px, 3vw, 28px) 0; border-bottom: 0.5px solid var(--mk-border); }
.mk-cl-entry:last-child { border-bottom: none; }
.mk-cl-entry.is-highlighted { background: linear-gradient(to right, var(--mk-accent-dim), transparent 40%); border-radius: var(--mk-r-lg); padding-left: 20px; padding-right: 20px; margin: 0 -20px; }

.mk-cl-date { color: var(--mk-muted); font-size: 13px; font-weight: 500; padding-top: 4px; }
.mk-cl-body-wrap { min-width: 0; }
.mk-cl-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
.mk-cl-cat { display: inline-block; padding: 2px 8px; background: var(--mk-bg3); color: var(--mk-text); border-radius: 4px; font-size: 11px; font-weight: 500; }
.mk-cl-pin { font-size: 11px; color: var(--mk-accent); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.mk-cl-entry-title { font-size: clamp(18px, 2vw, 22px); font-weight: 600; margin-bottom: 8px; line-height: 1.3; }
.mk-cl-body { color: var(--mk-text); font-size: 15px; line-height: 1.65; opacity: .85; white-space: pre-line; }

@media (max-width: 640px) {
  .mk-cl-entry { grid-template-columns: 1fr; gap: 6px; }
  .mk-cl-date { padding-top: 0; font-size: 12px; }
}
</style>
@endpush

@section('content')

<section class="mk-cl-hero">
  <div style="max-width: var(--mk-max); margin: 0 auto; padding: 0 var(--mk-gutter);">
    <div class="mk-cl-eyebrow">Changelog</div>
    <h1 class="mk-cl-title">What we shipped.</h1>
    <p class="mk-cl-sub">A running log of every meaningful improvement to Intake. Built one session at a time.</p>
  </div>
</section>

<section class="mk-cl-list">
  <div style="max-width: 820px; margin: 0 auto; padding: 0 var(--mk-gutter);">
    @forelse($entries as $entry)
      <article class="mk-cl-entry @if($entry->is_highlighted) is-highlighted @endif">
        <div class="mk-cl-date">{{ $entry->shipped_on->format('M j, Y') }}</div>
        <div class="mk-cl-body-wrap">
          <div class="mk-cl-meta">
            @if($entry->category)
              <span class="mk-cl-cat">{{ $entry->category }}</span>
            @endif
            @if($entry->is_highlighted)
              <span class="mk-cl-pin">★ Featured</span>
            @endif
          </div>
          <h2 class="mk-cl-entry-title">{{ $entry->title }}</h2>
          <div class="mk-cl-body">{{ $entry->body }}</div>
        </div>
      </article>
    @empty
      <div class="mk-cl-empty">
        Nothing published here yet. Come back soon.
      </div>
    @endforelse
  </div>
</section>

@endsection
