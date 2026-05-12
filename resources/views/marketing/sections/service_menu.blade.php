{{--
    Service menu section.
    Content schema:
      eyebrow      string   optional
      heading      string   optional
      subheading   string   optional
      note         string   optional footer note ("Prices editable. This is a starting point.")
      columns      int      1 or 2 (default 2) — side-by-side tables
      tables[]
        heading    string   table heading (e.g. "Haircuts", "Color Services")
        cols       string[] column headers (e.g. ["Service", "Duration", "From"])
        rows[]
          cells    string[] one cell per col
          section  bool     if true, renders as a section-header row (no price)
--}}
@php
    $tables  = $c['tables'] ?? [];
    $numCols = max(1, min(2, (int)($c['columns'] ?? 2)));
    if (is_string($tables)) {
        $decoded = json_decode($tables, true);
        $tables = is_array($decoded) ? $decoded : [];
    }
@endphp

<style>
.mk-smenu-wrap {
    display: grid;
    grid-template-columns: repeat({{ $numCols }}, 1fr);
    gap: 16px;
}
.mk-smenu-table-wrap {
    border: 0.5px solid var(--mk-border);
    border-radius: var(--mk-r-lg);
    overflow: hidden;
}
.mk-smenu-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.mk-smenu-table thead th {
    padding: 10px 14px;
    text-align: left;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--mk-muted);
    border-bottom: 0.5px solid var(--mk-border);
    background: rgba(255,255,255,.03);
}
.mk-smenu-table thead th.mk-smenu-th-head {
    font-size: 12px;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
    color: var(--mk-text);
    background: rgba(255,255,255,.04);
    border-bottom: 0.5px solid var(--mk-border2);
}
.mk-smenu-table td {
    padding: 9px 14px;
    border-bottom: 0.5px solid var(--mk-border);
    color: rgba(255,255,255,.65);
}
.mk-smenu-table tr:last-child td { border-bottom: none; }
.mk-smenu-table td:not(:first-child) { text-align: right; }
.mk-smenu-section-row td {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--mk-text);
    background: rgba(255,255,255,.04);
    padding: 8px 14px;
    border-bottom: 0.5px solid var(--mk-border2);
}
@media(max-width: 700px) { .mk-smenu-wrap { grid-template-columns: 1fr; } }
</style>

<section class="mk-section">
    <div class="mk-container">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
            <h2 class="mk-section-title">{{ $c['heading'] }}</h2>
        @endif
        @if(!empty($c['subheading']))
            <p class="mk-section-sub">{{ $c['subheading'] }}</p>
        @endif

        <div class="mk-smenu-wrap">
            @foreach($tables as $table)
                <div class="mk-smenu-table-wrap">
                    <table class="mk-smenu-table">
                        <thead>
                            @if(!empty($table['heading']))
                                <tr>
                                    <th class="mk-smenu-th-head" colspan="{{ count($table['cols'] ?? ['Service', 'Price']) }}">
                                        {{ $table['heading'] }}
                                    </th>
                                </tr>
                            @endif
                            <tr>
                                @foreach(($table['cols'] ?? ['Service', 'Price']) as $col)
                                    <th>{{ $col }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(($table['rows'] ?? []) as $row)
                                @if(!empty($row['section']))
                                    <tr class="mk-smenu-section-row">
                                        <td colspan="{{ count($table['cols'] ?? ['Service', 'Price']) }}">
                                            {{ $row['cells'][0] ?? '' }}
                                        </td>
                                    </tr>
                                @else
                                    <tr>
                                        @foreach(($row['cells'] ?? []) as $cell)
                                            <td>{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>

        @if(!empty($c['note']))
            <p style="font-size:12px;color:var(--mk-dim);margin-top:12px">{{ $c['note'] }}</p>
        @endif
    </div>
</section>
