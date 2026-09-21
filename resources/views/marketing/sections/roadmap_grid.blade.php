{{--
    Dynamic section: renders the roadmap grid.
    MARKER-CL-RM-LAYOUT — forward-looking first, shipped collapsed.

    Variables in scope:
      $c       — section content array (intro_text)
      $section — TenantPageSection model
--}}
@php
    // Notes here use double-slash; Blade comment syntax breaks inside this block.
    use App\Models\RoadmapEntry;

    $entries = RoadmapEntry::published()
        ->orderBy('display_order')
        ->orderBy('created_at')
        ->get()
        ->groupBy('status');

    // The model's STATUSES order puts Shipped first, which buried every
    // forward-looking item under ~40 finished ones. The page order is its own.
    $pageOrder = ['in_progress', 'next_up', 'considering', 'shipped'];
    $openByDefault = ['in_progress', 'next_up'];

    $orderedGroups = [];
    foreach ($pageOrder as $statusKey) {
        if (isset($entries[$statusKey]) && $entries[$statusKey]->count() > 0) {
            $orderedGroups[$statusKey] = $entries[$statusKey];
        }
    }

    $statusLabels = RoadmapEntry::STATUSES;
    $introText = $c['intro_text'] ?? '';
@endphp

<style>
  .mk-rm2 { --mk-rm2-progress:#f0c46a; --mk-rm2-next:#7cb8f6; }
  .mk-rm2 summary { list-style:none; cursor:pointer; }
  .mk-rm2 summary::-webkit-details-marker { display:none; }
  .mk-rm2 .mk-rm2-chev { transition:transform .15s; }
  .mk-rm2 details[open] > summary .mk-rm2-chev { transform:rotate(90deg); }
</style>

<section class="mk-section mk-rm2 {{ $padding }}" style="{{ $inlineStyle }}">
  <div class="mk-container" style="max-width:760px;margin:0 auto">
    @if($introText)
      <p class="mk-section-intro" style="font-size:15px;color:var(--mk-muted);max-width:680px;margin:0 auto 36px;text-align:center;line-height:1.55">
        {{ $introText }}
      </p>
    @endif

    @forelse($orderedGroups as $statusKey => $groupEntries)
      @php
        $dot = match ($statusKey) {
            'in_progress' => 'var(--mk-rm2-progress)',
            'next_up'     => 'var(--mk-rm2-next)',
            'shipped'     => 'var(--mk-accent)',
            default       => 'var(--mk-muted)',
        };
      @endphp
      <details {{ in_array($statusKey, $openByDefault, true) ? 'open' : '' }} style="border-top:0.5px solid var(--mk-border);{{ $loop->last ? 'border-bottom:0.5px solid var(--mk-border);' : '' }}">
        <summary style="display:flex;align-items:center;gap:12px;padding:18px 2px">
          <span style="width:9px;height:9px;border-radius:50%;background:{{ $dot }};flex:0 0 auto"></span>
          <span style="font-size:19px;font-weight:700;letter-spacing:-.015em">{{ $statusLabels[$statusKey] ?? ucfirst($statusKey) }}</span>
          <span style="font-size:13px;color:var(--mk-muted)">{{ $groupEntries->count() }}</span>
          <span class="mk-rm2-chev" style="margin-left:auto;color:var(--mk-muted);font-size:13px">›</span>
        </summary>

        @if($statusKey === 'considering')
          <p style="font-size:13px;color:var(--mk-muted);margin:-8px 0 8px 21px">Ideas we're weighing. No promises on these.</p>
        @endif

        <div style="padding:0 0 14px 21px">
          @foreach($groupEntries as $entry)
            <div style="display:grid;grid-template-columns:1fr auto;gap:14px;padding:10px 2px;align-items:baseline;{{ $loop->first ? '' : 'border-top:0.5px solid var(--mk-border);' }}">
              <div>
                <div style="font-weight:600;font-size:15px;line-height:1.35">
                  {{ $entry->title }}
                  @if($entry->category && $statusKey !== 'shipped')
                    <span style="font-size:10.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--mk-muted);margin-left:8px;font-weight:600">{{ $entry->category }}</span>
                  @endif
                </div>
                @if($statusKey !== 'shipped')
                  <div style="font-size:13.5px;color:var(--mk-muted);margin-top:2px;line-height:1.5">{{ $entry->body }}</div>
                @endif
              </div>
              <div style="font-size:12px;white-space:nowrap;color:{{ $statusKey === 'shipped' ? 'var(--mk-accent)' : 'var(--mk-muted)' }}">
                @if($statusKey === 'shipped')
                  {{ $entry->shipped_on ? 'Shipped ' . $entry->shipped_on->format('M j') : 'Shipped' }}
                @elseif($entry->target_month)
                  {{ $entry->target_month->format('F Y') }}
                @elseif($entry->rough_timeframe)
                  {{ $entry->rough_timeframe }}
                @endif
              </div>
            </div>
          @endforeach
        </div>

        @if($statusKey === 'shipped')
          <a href="{{ route('marketing.changelog') }}" style="display:inline-block;margin:0 0 16px 21px;font-size:13px;color:var(--mk-accent);text-decoration:none">
            See everything we've shipped in the changelog →
          </a>
        @endif
      </details>
    @empty
      <p style="text-align:center;color:var(--mk-muted);font-size:14px;padding:40px 0">
        Nothing on the roadmap yet. Check back soon.
      </p>
    @endforelse
  </div>
</section>
