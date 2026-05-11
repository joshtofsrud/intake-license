{{--
    Dynamic section: renders the changelog list.
    Reads ChangelogEntry rows reverse-chronologically. Like roadmap_grid,
    the editable content here is only intro_text — actual entries are
    edited via Filament's Changelog resource.

    Variables in scope:
      $c       — section content array
      $section — TenantPageSection model
--}}
@php
    use App\Models\ChangelogEntry;

    $entries = ChangelogEntry::published()
        ->orderByDesc('is_highlighted')
        ->orderByDesc('shipped_on')
        ->orderByDesc('created_at')
        ->get();

    $introText = $c['intro_text'] ?? '';
@endphp

<section class="mk-section {{ $padding }}" style="{{ $inlineStyle }}">
  <div class="mk-container">
    @if($introText)
      <p class="mk-section-intro" style="font-size:15px;color:var(--mk-muted);max-width:680px;margin:0 auto 36px;text-align:center;line-height:1.55">
        {{ $introText }}
      </p>
    @endif

    <div class="mk-cl-list" style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:18px">
      @foreach($entries as $entry)
        <article class="mk-cl-entry" style="background:var(--mk-bg2);border:0.5px solid var(--mk-border);border-radius:12px;padding:20px 24px;@if($entry->is_highlighted)border-left:3px solid var(--mk-accent);@endif">
          <header style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;margin-bottom:10px;flex-wrap:wrap">
            <div style="display:flex;align-items:baseline;gap:10px">
              @if($entry->category)
                <span style="font-size:10.5px;text-transform:uppercase;letter-spacing:.1em;color:var(--mk-accent);font-weight:600">{{ $entry->category }}</span>
              @endif
              <h3 style="margin:0;font-size:16px;font-weight:600;letter-spacing:-.01em">{{ $entry->title }}</h3>
            </div>
            @if($entry->shipped_on)
              <span style="font-size:12.5px;color:var(--mk-muted);white-space:nowrap">{{ $entry->shipped_on->format('M j, Y') }}</span>
            @endif
          </header>
          <p style="margin:0;font-size:14px;color:var(--mk-muted);line-height:1.55">{{ $entry->body }}</p>
        </article>
      @endforeach

      @if($entries->isEmpty())
        <p style="text-align:center;color:var(--mk-muted);font-size:14px;padding:40px 0">
          No changelog entries yet. Check back soon.
        </p>
      @endif
    </div>
  </div>
</section>
