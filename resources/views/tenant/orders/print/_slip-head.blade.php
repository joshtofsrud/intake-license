{{-- MARKER-ORDER-PRINT: receipt-printer page set-up, shared by the pick list and bag tag.
     Same paper, logo and feed settings as the register's receipt. --}}
@php
  $pageMm  = ($print['paper'] ?? '80mm') === '58mm' ? '46mm' : '70mm';
  $logoMax = ['small'=>'12mm','medium'=>'18mm','large'=>'26mm','xl'=>'34mm'][$print['logo_size'] ?? 'medium'] ?? '18mm';
  $logoUrl = ! empty($print['logo_path']) ? asset('storage/' . ltrim($print['logo_path'], '/')) : null;
  $feedMm  = (int) ($print['feed_mm'] ?? 0) > 0 ? ((int) $print['feed_mm']) . 'mm' : null;
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @page { size: {{ $pageMm }} auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { width: {{ $pageMm }}; color: #000; font-family: "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; }
  .slip { width: 100%; padding: 4mm 3mm 3mm; font-size: 11px; line-height: 1.45; overflow: hidden; }
  .slip + .slip { break-before: page; page-break-before: always; }
  .ctr { text-align: center; }
  .hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .shop { font-size: 13px; font-weight: 700; letter-spacing: .04em; }
  .logo { max-width: 100%; max-height: {{ $logoMax }}; display: block; margin: 0 auto 4px; }
  .lbl { font-size: 10px; letter-spacing: .16em; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  td { padding: 3px 0; font-size: 11px; vertical-align: top; word-break: break-word; overflow-wrap: anywhere; }
  .box { width: 4mm; } .box span { display: inline-block; width: 3mm; height: 3mm; border: 1px solid #000; margin-top: 2px; }
  .qty { width: 9mm; text-align: right; font-weight: 700; padding-right: 2mm; }
  .bin { font-size: 10px; }
  .feed { height: {{ $feedMm ?? '0' }}; }
  @media screen {
    body { margin: {{ ($embed ?? false) ? '8px' : '64px' }} auto 24px; }
    .slip { box-shadow: 0 0 0 1px #ddd; margin-bottom: 16px; }
    .printbar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff; font-family: system-ui, sans-serif; font-size: 13px; padding: 10px 16px; display: flex; gap: 12px; align-items: center; justify-content: center; }
    .printbar button { background: #BEF264; color: #0a0a0a; border: 0; font-weight: 600; padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  }
  @media print { .printbar { display: none; } }
</style>
