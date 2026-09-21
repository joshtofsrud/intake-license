{{--
    Dynamic section: renders the changelog list.
    MARKER-CL-RM-LAYOUT — labelled highlights, then the timeline by month.

    Variables in scope:
      $c       — section content array (intro_text)
      $section — TenantPageSection model
--}}
@php
    // Notes here use double-slash; Blade comment syntax breaks inside this block.
    use App\Models\ChangelogEntry;
    use Illuminate\Support\Carbon;

    // Newest first. Pinning does NOT reorder the timeline any more.
    $entries = ChangelogEntry::published()
        ->orderByDesc('shipped_on')
        ->orderByDesc('created_at')
        ->get();

    $highlights = $entries->where('is_highlighted', true)->values();
    $hlShown    = $highlights->take(3);
    $hlMore     = $highlights->slice(3)->values();

    $months = $entries->groupBy(function ($e) {
        return $e->shipped_on ? $e->shipped_on->format('Y-m') : 'earlier';
    });

    $monthLabel = function ($key) {
        return $key === 'earlier' ? 'Earlier' : Carbon::createFromFormat('Y-m', $key)->format('F Y');
    };

    $introText = $c['intro_text'] ?? '';
@endphp

<style>
  .mk-cl2 summary { list-style:none; cursor:pointer; }
  .mk-cl2 summary::-webkit-details-marker { display:none; }
  .mk-cl2 .mk-cl2-chev { transition:transform .15s; }
  .mk-cl2 details[open] > summary .mk-cl2-chev { transform:rotate(90deg); }
  .mk-cl2 .mk-cl2-more[open] > summary { display:none; }
  .mk-cl2 .mk-cl2-hl { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
  @media (max-width:680px) { .mk-cl2 .mk-cl2-hl { grid-template-columns:1fr; } }
</style>

<section class="mk-section mk-cl2 {{ $padding }}" style="{{ $inlineStyle }}">
  <div class="mk-container" style="max-width:760px;margin:0 auto">
    @if($introText)
      <p class="mk-section-intro" style="font-size:15px;color:var(--mk-muted);max-width:680px;margin:0 auto 36px;text-align:center;line-height:1.55">
        {{ $introText }}
      </p>
    @endif

    @if($highlights->isNotEmpty())
      <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:12px">
        <h2 style="margin:0;font-size:13px;letter-spacing:.09em;text-transform:uppercase;color:var(--mk-accent);font-weight:700">Highlights</h2>
        <span style="font-size:12.5px;color:var(--mk-muted)">The changes worth knowing about</span>
      </div>

      <div class="mk-cl2-hl">
        @foreach($hlShown as $h)
          @include('marketing.sections._changelog_highlight', ['h' => $h])
        @endforeach
      </div>

      @if($hlMore->isNotEmpty())
        <details class="mk-cl2-more" style="margin-top:12px">
          <summary style="display:inline-block;font-size:13px;color:var(--mk-muted);border:0.5px solid var(--mk-border);border-radius:99px;padding:5px 13px">
            Show {{ $hlMore->count() }} more {{ $hlMore->count() === 1 ? 'highlight' : 'highlights' }}
          </summary>
          <div class="mk-cl2-hl" style="margin-top:10px">
            @foreach($hlMore as $h)
              @include('marketing.sections._changelog_highlight', ['h' => $h])
            @endforeach
          </div>
        </details>
      @endif

      <div style="height:44px"></div>
    @endif

    @forelse($months as $key => $monthEntries)
      <details {{ $loop->index < 2 ? 'open' : '' }} style="border-top:0.5px solid var(--mk-border);{{ $loop->last ? 'border-bottom:0.5px solid var(--mk-border);' : '' }}">
        <summary style="display:flex;align-items:baseline;gap:12px;padding:18px 2px">
          <span style="font-size:19px;font-weight:700;letter-spacing:-.015em">{{ $monthLabel($key) }}</span>
          <span style="font-size:13px;color:var(--mk-muted)">{{ $monthEntries->count() }} {{ $monthEntries->count() === 1 ? 'update' : 'updates' }}</span>
          <span class="mk-cl2-chev" style="margin-left:auto;color:var(--mk-muted);font-size:13px">›</span>
        </summary>
        <div style="padding:0 0 14px">
          @foreach($monthEntries as $entry)
            <div style="display:grid;grid-template-columns:58px 1fr;gap:14px;padding:10px 2px;{{ $loop->first ? '' : 'border-top:0.5px solid var(--mk-border);' }}">
              <div style="font-size:12.5px;color:var(--mk-muted);padding-top:2px;font-variant-numeric:tabular-nums">
                {{ $entry->shipped_on ? $entry->shipped_on->format('M j') : '' }}
              </div>
              <div>
                <div style="font-weight:600;font-size:15px;line-height:1.35">
                  {{ $entry->title }}
                  @if($entry->is_highlighted)
                    <span style="font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mk-accent);margin-left:7px">Highlight</span>
                  @elseif($entry->category)
                    <span style="font-size:10.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--mk-muted);margin-left:8px;font-weight:600">{{ $entry->category }}</span>
                  @endif
                </div>
                <div style="font-size:13.5px;color:var(--mk-muted);margin-top:2px;line-height:1.5">{{ $entry->body }}</div>
              </div>
            </div>
          @endforeach
        </div>
      </details>
    @empty
      <p style="text-align:center;color:var(--mk-muted);font-size:14px;padding:40px 0">
        No changelog entries yet. Check back soon.
      </p>
    @endforelse
  </div>
</section>
