{{-- Block: finish setup (Brief). Draws nothing once setup is done. --}}
@php $bs = \App\Support\Dashboard\BriefView::setup($progress ?? []); @endphp
    @if (empty($progress['all_done']) && $bs['next'])
      <div class="mb-setup">
        <span class="k">Finish setup</span>
        <span class="bar"><i style="width:{{ round($bs['done'] / 3 * 100) }}%"></i></span>
        <span class="n">{{ $bs['done'] }} of 3 done</span>
        <a href="{{ $bs['next'][1] }}">{{ $bs['next'][0] }} →</a>
      </div>
    @endif
