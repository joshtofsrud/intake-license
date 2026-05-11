{{--
    Dynamic section: renders the roadmap grid.
    Reads RoadmapEntry rows from the DB and groups by status. The editable
    content of this section is only the section's surrounding chrome (extra
    intro text). Actual entries are edited via Filament's Roadmap resource.

    Variables in scope:
      $c       — section content array (any intro_text the editor set)
      $section — TenantPageSection model
--}}
@php
    use App\Models\RoadmapEntry;

    $entries = RoadmapEntry::published()
        ->orderBy('display_order')
        ->orderBy('created_at')
        ->get()
        ->groupBy('status');

    // Stable status order regardless of which buckets have entries.
    $orderedGroups = [];
    foreach (array_keys(RoadmapEntry::STATUSES) as $statusKey) {
        if (isset($entries[$statusKey]) && $entries[$statusKey]->count() > 0) {
            $orderedGroups[$statusKey] = $entries[$statusKey];
        }
    }

    $statusLabels = RoadmapEntry::STATUSES;
    $introText = $c['intro_text'] ?? '';
@endphp

<section class="mk-section {{ $padding }}" style="{{ $inlineStyle }}">
  <div class="mk-container">
    @if($introText)
      <p class="mk-section-intro" style="font-size:15px;color:var(--mk-muted);max-width:680px;margin:0 auto 36px;text-align:center;line-height:1.55">
        {{ $introText }}
      </p>
    @endif

    @foreach($orderedGroups as $statusKey => $groupEntries)
      <div class="mk-rm-group" style="margin-bottom:48px">
        <div class="mk-rm-group-head" style="display:flex;align-items:baseline;gap:14px;margin-bottom:18px">
          <h3 style="margin:0;font-size:18px;font-weight:600;letter-spacing:-.015em">
            {{ $statusLabels[$statusKey] ?? ucfirst($statusKey) }}
          </h3>
          <span style="font-size:12.5px;color:var(--mk-muted)">· {{ $groupEntries->count() }} item{{ $groupEntries->count() === 1 ? '' : 's' }}</span>
        </div>
        <div class="mk-rm-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
          @foreach($groupEntries as $entry)
            <article class="mk-rm-card" style="background:var(--mk-bg2);border:0.5px solid var(--mk-border);border-radius:12px;padding:18px 20px">
              <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px">
                @if($entry->category)
                  <span style="font-size:10.5px;text-transform:uppercase;letter-spacing:.1em;color:var(--mk-accent);font-weight:600">{{ $entry->category }}</span>
                @else
                  <span></span>
                @endif
                @if($statusKey === 'shipped' && $entry->shipped_on)
                  <span style="font-size:11.5px;color:var(--mk-muted)">Shipped {{ $entry->shipped_on->format('M j') }}</span>
                @elseif($entry->target_month)
                  <span style="font-size:11.5px;color:var(--mk-muted)">{{ $entry->target_month->format('F Y') }}</span>
                @elseif($entry->rough_timeframe)
                  <span style="font-size:11.5px;color:var(--mk-muted)">{{ $entry->rough_timeframe }}</span>
                @endif
              </div>
              <h4 style="margin:0 0 8px;font-size:15px;font-weight:600;line-height:1.3">{{ $entry->title }}</h4>
              <p style="margin:0;font-size:13.5px;color:var(--mk-muted);line-height:1.5">{{ $entry->body }}</p>
            </article>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>
</section>
