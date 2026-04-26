@extends('layouts.tenant.app')
@php $pageTitle = "What's Coming"; @endphp

@push('styles')
<style>
.wn-page { max-width: 920px; }
.wn-hero { padding: 24px 0 32px; border-bottom: 0.5px solid var(--ia-border); margin-bottom: 32px; }
.wn-hero-title { font-size: 24px; font-weight: 700; margin-bottom: 6px; }
.wn-hero-sub { font-size: 14px; opacity: .55; max-width: 540px; }
.wn-disclaimer { margin-top: 8px; font-size: 11px; opacity: .35; }

.wn-section { margin-bottom: 36px; }
.wn-status-head { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.wn-status-dot { width: 9px; height: 9px; border-radius: 50%; }
.wn-status-dot.s-shipped { background: var(--ia-accent); }
.wn-status-dot.s-in_progress { background: #EF9F27; }
.wn-status-dot.s-next_up { background: #6EA0FF; }
.wn-status-dot.s-considering { background: rgba(255,255,255,.25); }
.wn-status-name { font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
.wn-status-count { opacity: .45; font-size: 12px; }

.wn-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.wn-card { padding: 16px; background: rgba(255,255,255,.02); border: 0.5px solid var(--ia-border); border-radius: var(--ia-r-lg); }
.wn-card.s-shipped { border-color: rgba(190,242,100,.18); }

.wn-card-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.wn-card-cat { display: inline-block; padding: 2px 6px; background: rgba(255,255,255,.05); border-radius: 4px; font-size: 10px; font-weight: 500; }
.wn-card-time { opacity: .5; font-size: 11px; font-weight: 500; }
.wn-card-title { font-size: 15px; font-weight: 600; margin-bottom: 6px; line-height: 1.3; }
.wn-card-body { font-size: 13px; line-height: 1.55; opacity: .75; white-space: pre-line; }

.wn-empty { text-align: center; padding: 64px 0; opacity: .5; font-size: 14px; }
</style>
@endpush

@section('content')

<div class="wn-page">
  <div class="wn-hero">
    <h1 class="wn-hero-title">What's Coming</h1>
    <p class="wn-hero-sub">Where Intake is headed. Plans change as we learn from shops using the product.</p>
    <p class="wn-disclaimer">Timing is rough. We commit to direction, not dates.</p>
  </div>

  @php $statusLabels = \App\Models\RoadmapEntry::STATUSES; @endphp

  @forelse($groups as $statusKey => $items)
    <section class="wn-section">
      <div class="wn-status-head">
        <span class="wn-status-dot s-{{ $statusKey }}"></span>
        <span class="wn-status-name">{{ $statusLabels[$statusKey] ?? ucfirst($statusKey) }}</span>
        <span class="wn-status-count">{{ $items->count() }} {{ $items->count() === 1 ? 'item' : 'items' }}</span>
      </div>
      <div class="wn-grid">
        @foreach($items as $entry)
          <article class="wn-card s-{{ $statusKey }}">
            <div class="wn-card-meta">
              @if($entry->category)
                <span class="wn-card-cat">{{ $entry->category }}</span>
              @endif
              @if($entry->rough_timeframe)
                <span class="wn-card-time">{{ $entry->rough_timeframe }}</span>
              @endif
            </div>
            <h2 class="wn-card-title">{{ $entry->title }}</h2>
            <div class="wn-card-body">{{ $entry->body }}</div>
          </article>
        @endforeach
      </div>
    </section>
  @empty
    <div class="wn-empty">Nothing on the roadmap yet.</div>
  @endforelse
</div>

@endsection
