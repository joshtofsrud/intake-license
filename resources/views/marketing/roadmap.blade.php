@extends('marketing.layout')

@section('title', 'Roadmap — Intake')
@section('meta_description', 'What is coming next in Intake. Grouped by status, public-friendly framing.')

@push('styles')
<style>
.mk-rm-hero { padding: clamp(48px, 7vw, 88px) 0 clamp(24px, 4vw, 40px); text-align: center; border-bottom: 0.5px solid var(--mk-border); }
.mk-rm-eyebrow { display: inline-block; padding: 4px 10px; background: var(--mk-accent-dim); color: var(--mk-accent); border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 16px; }
.mk-rm-title { font-size: clamp(36px, 5vw, 56px); font-weight: 800; letter-spacing: -.02em; margin-bottom: 12px; }
.mk-rm-sub { color: var(--mk-muted); font-size: clamp(16px, 1.6vw, 18px); max-width: 600px; margin: 0 auto; }
.mk-rm-disclaimer { margin-top: 16px; color: var(--mk-dim); font-size: 12px; max-width: 560px; margin-left: auto; margin-right: auto; }

.mk-rm-section { padding: clamp(40px, 6vw, 72px) 0 0; }
.mk-rm-section:last-child { padding-bottom: clamp(40px, 6vw, 72px); }
.mk-rm-status-head { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.mk-rm-status-dot { width: 10px; height: 10px; border-radius: 50%; }
.mk-rm-status-dot.s-shipped { background: var(--mk-accent); }
.mk-rm-status-dot.s-in_progress { background: #EF9F27; }
.mk-rm-status-dot.s-next_up { background: #6EA0FF; }
.mk-rm-status-dot.s-considering { background: var(--mk-dim); }
.mk-rm-status-name { font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--mk-text); }
.mk-rm-status-count { color: var(--mk-muted); font-size: 13px; font-weight: 500; }

.mk-rm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.mk-rm-card { padding: 20px; background: var(--mk-bg2); border: 1px solid var(--mk-border); border-radius: var(--mk-r-lg); }
.mk-rm-card.s-shipped { border-color: rgba(190,242,100,.25); }

.mk-rm-card-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.mk-rm-card-cat { display: inline-block; padding: 2px 8px; background: var(--mk-bg3); color: var(--mk-text); border-radius: 4px; font-size: 11px; font-weight: 500; }
.mk-rm-card-time { color: var(--mk-muted); font-size: 11px; font-weight: 500; }
.mk-rm-card-title { font-size: 16px; font-weight: 600; margin-bottom: 8px; line-height: 1.3; }
.mk-rm-card-body { color: var(--mk-text); font-size: 14px; line-height: 1.6; opacity: .8; white-space: pre-line; }

.mk-rm-empty { text-align: center; padding: 48px 0; color: var(--mk-muted); font-size: 14px; }
</style>
@endpush

@section('content')

<section class="mk-rm-hero">
  <div style="max-width: var(--mk-max); margin: 0 auto; padding: 0 var(--mk-gutter);">
    <div class="mk-rm-eyebrow">Roadmap</div>
    <h1 class="mk-rm-title">What is coming.</h1>
    <p class="mk-rm-sub">An honest look at where Intake is heading. Plans change as we learn from shops using the product.</p>
    <p class="mk-rm-disclaimer">Timing is intentionally rough. We commit to direction, not dates.</p>
  </div>
</section>

@php
  $statusLabels = \App\Models\RoadmapEntry::STATUSES;
@endphp

@forelse($groups as $statusKey => $items)
  <section class="mk-rm-section">
    <div style="max-width: var(--mk-max); margin: 0 auto; padding: 0 var(--mk-gutter);">
      <div class="mk-rm-status-head">
        <span class="mk-rm-status-dot s-{{ $statusKey }}"></span>
        <span class="mk-rm-status-name">{{ $statusLabels[$statusKey] ?? ucfirst($statusKey) }}</span>
        <span class="mk-rm-status-count">· {{ $items->count() }} {{ $items->count() === 1 ? 'item' : 'items' }}</span>
      </div>
      <div class="mk-rm-grid">
        @foreach($items as $entry)
          <article class="mk-rm-card s-{{ $statusKey }}">
            <div class="mk-rm-card-meta">
              @if($entry->category)
                <span class="mk-rm-card-cat">{{ $entry->category }}</span>
              @endif
              @if($entry->rough_timeframe)
                <span class="mk-rm-card-time">{{ $entry->rough_timeframe }}</span>
              @endif
            </div>
            <h2 class="mk-rm-card-title">{{ $entry->title }}</h2>
            <div class="mk-rm-card-body">{{ $entry->body }}</div>
          </article>
        @endforeach
      </div>
    </div>
  </section>
@empty
  <section class="mk-rm-section">
    <div style="max-width: var(--mk-max); margin: 0 auto; padding: 0 var(--mk-gutter);">
      <div class="mk-rm-empty">No roadmap items published yet.</div>
    </div>
  </section>
@endforelse

@endsection
