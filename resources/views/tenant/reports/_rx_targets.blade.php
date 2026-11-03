{{-- Targets form on the Operations tab. $tg is built by TabPanels. Saves through tenant.reports.targets. (MARKER-REPORT-TABS-ENGINE) --}}
<form method="POST" action="{{ $tg['action'] }}" class="rx-targets">
  @csrf
  <div class="rb-tbl"><table class="ia-table rx-tbl">
    <thead><tr><th>Measure</th><th class="num">This range</th><th class="num">Target</th><th>Status</th></tr></thead>
    <tbody>
      @foreach($tg['rows'] as $r)
        <tr>
          <td>{{ $r['label'] }}</td>
          <td class="num">{{ $r['actual'] }}</td>
          <td class="num"><input class="rb-inp" style="width:96px;text-align:right" type="number" step="0.1" min="0" max="1000000" name="targets[{{ $r['key'] }}]" value="{{ $r['target'] }}" placeholder="—" aria-label="Target for {{ $r['label'] }}"></td>
          <td>
            @if($r['status'] === 'on')<span class="rb-pill good">On target</span>
            @elseif($r['status'] === 'under')<span class="rb-pill warn">Under target</span>
            @else<span class="rb-hint">{{ $r['target'] === null ? 'No target set' : 'Nothing to compare yet' }}</span>@endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table></div>
  <div class="rb-bar" style="margin-top:14px">
    <label class="rb-hint" for="rx-spot">Spotlight category</label>
    <select class="rb-sel" id="rx-spot" name="spotlight">
      <option value="">None</option>
      @foreach($tg['options'] as $o)
        <option value="{{ $o['v'] }}" @selected($tg['spot'] === $o['v'])>{{ $o['l'] }}</option>
      @endforeach
    </select>
    <span class="sp"></span>
    <button class="ia-btn ia-btn--primary" type="submit">Save targets</button>
  </div>
  <div class="rx-line">Leave a target blank and it stays blank: no status is shown for a number you haven't set. The spotlight marks the one category that matters most in your shop, and isn't tied to any trade.</div>
</form>
