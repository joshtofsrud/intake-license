{{--
  MARKER-SHOP-PORT4: Comparison table on shop sites. Port of
  marketing/sections/comparison_table. The first column is you and is highlighted.
  Content: eyebrow, heading, subheading, competitors[] (column names),
           rows[{feature, values[]}] where a value is yes / no / extra (add-on) or any text.
--}}
@php
  $c  = $c ?? [];
  $sh = \App\Support\ShopSectionShell::make($c, $section, 'sct');
  $ctId = $sh['id'];
  $cols = $c['competitors'] ?? [];
  if (is_string($cols)) { $d = json_decode($cols, true); $cols = is_array($d) ? $d : []; }
  $cols = array_values(array_map(fn ($x) => (string) $x, is_array($cols) ? $cols : []));
  if (! $cols) $cols = ['Us', 'Others'];
  $rows = $c['rows'] ?? [];
  if (is_string($rows)) { $d = json_decode($rows, true); $rows = is_array($d) ? $d : []; }
  $rows = array_values(array_filter(is_array($rows) ? $rows : [], fn ($r) => is_array($r) && trim((string) ($r['feature'] ?? '')) !== ''));
  $cell = function ($v) {
      return match (strtolower(trim((string) $v))) {
          'yes', 'true', '1'          => '<span class="ct-yes" aria-label="Yes">✓</span>',
          'no', 'false', '0', ''      => '<span class="ct-no" aria-label="No">✕</span>',
          'extra', 'add-on', 'paid'   => '<span class="ct-extra">Add-on</span>',
          default                     => '<span class="ct-txt">' . e($v) . '</span>',
      };
  };
@endphp
<style>
  {!! $sh['css'] !!}
  .{{ $ctId }} .ct-outer { max-width: 960px; margin: 0 auto; padding: 0 clamp(20px, 5vw, 48px); }
  .{{ $ctId }} .ct-head { text-align: center; margin-bottom: 40px; }
  .{{ $ctId }} .ct-eyebrow { font-family: ui-monospace, monospace; font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: {{ $sh['accent'] }}; margin-bottom: 14px; }
  .{{ $ctId }} .ct-head h2 { font-size: clamp(26px, 3.6vw, 44px); font-weight: var(--p-heading-weight, 600); text-transform: var(--p-heading-transform, none); line-height: 1.1; letter-spacing: -.02em; margin: 0; }
  .{{ $ctId }} .ct-head p { opacity: .7; font-size: 17px; line-height: 1.6; margin: 12px auto 0; max-width: 620px; }
  .{{ $ctId }} .ct-scroll { overflow-x: auto; }
  .{{ $ctId }} table { width: 100%; border-collapse: collapse; border-radius: 12px; overflow: hidden; background: color-mix(in srgb, currentColor 3%, transparent); border: 1px solid color-mix(in srgb, currentColor 10%, transparent); }
  .{{ $ctId }} thead tr { background: color-mix(in srgb, currentColor 5%, transparent); }
  .{{ $ctId }} th { padding: 14px 18px; font-size: 13px; font-weight: 700; text-align: center; }
  .{{ $ctId }} th:first-child { text-align: left; font-size: 11px; font-weight: 600; opacity: .6; text-transform: uppercase; letter-spacing: .08em; }
  .{{ $ctId }} th.ct-us { color: {{ $sh['accent'] }}; background: color-mix(in srgb, {{ $sh['accent'] }} 8%, transparent); }
  .{{ $ctId }} td { padding: 14px 18px; text-align: center; border-top: 1px solid color-mix(in srgb, currentColor 10%, transparent); }
  .{{ $ctId }} td:first-child { text-align: left; font-size: 14px; font-weight: 500; }
  .{{ $ctId }} td.ct-us { background: color-mix(in srgb, {{ $sh['accent'] }} 5%, transparent); }
  .{{ $ctId }} .ct-yes { color: {{ $sh['accent'] }}; font-size: 18px; font-weight: 600; }
  .{{ $ctId }} .ct-no { opacity: .35; font-size: 18px; }
  .{{ $ctId }} .ct-extra { color: #b7791f; font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; }
  .{{ $ctId }} .ct-txt { font-size: 13px; }
</style>
<section {!! $sh['attrs'] !!}>
  <div class="ct-outer">
    @if(!empty($c['eyebrow']) || $sh['heading'] !== '' || !empty($c['subheading']))
      <div class="ct-head">
        @if(!empty($c['eyebrow']))<div class="ct-eyebrow">{{ $c['eyebrow'] }}</div>@endif
        @if($sh['heading'] !== '')<h2>{!! $sh['heading'] !!}</h2>@endif
        @if(!empty($c['subheading']))<p>{{ $c['subheading'] }}</p>@endif
      </div>
    @endif
    @if($rows)
      <div class="ct-scroll">
        <table>
          <thead>
            <tr>
              <th scope="col">Feature</th>
              @foreach($cols as $i => $name)<th scope="col" class="{{ $i === 0 ? 'ct-us' : '' }}">{{ $name }}</th>@endforeach
            </tr>
          </thead>
          <tbody>
            @foreach($rows as $row)
              @php $vals = is_array($row['values'] ?? null) ? array_values($row['values']) : []; @endphp
              <tr>
                <td>{{ $row['feature'] }}</td>
                @foreach($cols as $i => $name)<td class="{{ $i === 0 ? 'ct-us' : '' }}">{!! $cell($vals[$i] ?? '') !!}</td>@endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</section>
