<x-filament-panels::page>
{{-- MARKER-BRAND --}}
<style>
  .br{--br-line:var(--ia-border,rgba(127,127,127,.22));--br-accent:#8b7cf6}
  .br-legend{border:1px solid rgba(139,124,246,.3);background:rgba(139,124,246,.07);border-radius:12px;padding:12px 16px;font-size:13px;line-height:1.65;margin-bottom:18px}
  .br-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
  .br-slot{border:1px solid var(--br-line);border-radius:12px;overflow:hidden}
  .br-pv{height:130px;display:grid;place-items:center;background:#0c0c0c}
  .br-pv.lt{background:#f7f7f4}
  .br-pv img{max-width:72%;max-height:70px}
  .br-ft{padding:10px 12px;font-size:12.5px}
  .br-ft b{display:block;font-size:13.5px;margin-bottom:2px}
  .br-ft .h{opacity:.6;margin-bottom:8px}
  .br-file{position:relative;display:inline-block;overflow:hidden;border:1px solid var(--br-line);border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:600;cursor:pointer}
  .br-file input{position:absolute;inset:0;opacity:0;cursor:pointer}
  .br-new{font-size:12px;color:var(--br-accent);margin-left:8px}
  .br-used{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:13px;margin-top:18px}
  .br-used div{border:1px solid var(--br-line);border-radius:8px;padding:8px 12px}
  .br-used small{display:block;opacity:.55;font-size:11.5px}
  .br-hist{margin-top:18px;border:1px solid var(--br-line);border-radius:12px;padding:12px 16px;font-size:13px}
  .br-hist .r{display:flex;justify-content:space-between;align-items:center;padding:6px 0}
  .br-bar{position:sticky;bottom:0;margin-top:18px;display:flex;justify-content:flex-end;gap:10px;align-items:center;padding:12px 0;background:inherit}
  .br-btn{border:1px solid var(--br-line);background:none;color:inherit;font:inherit;font-size:13px;font-weight:600;padding:7px 14px;border-radius:8px;cursor:pointer}
  .br-btn--pri{background:var(--br-accent);border-color:var(--br-accent);color:#14121f}
  .br-btn:disabled{opacity:.4;cursor:not-allowed}
  @media (max-width: 900px){.br-grid,.br-used{grid-template-columns:1fr}}
</style>
<div class="br">
  <div class="br-legend">
    <b>One place for Intake's own brand.</b> What you save here appears everywhere Intake shows its brand — the marketing site, emails Intake sends,
    error pages, investor pages, sign-in screens and "Powered by Intake" on shops' pages. <b>Shops' own logos are not affected.</b>
    Nothing changes until you press <b>Save</b>; the set you replace is kept below so you can switch back.
  </div>

  <div class="br-grid">
    @foreach($slots as $slot => $meta)
      <div class="br-slot" wire:key="slot-{{ $slot }}">
        <div class="br-pv {{ in_array($slot, ['logo_light', 'email'], true) ? 'lt' : '' }}">
          @php $up = $this->up[$slot] ?? null; @endphp
          @if($up && method_exists($up, 'isPreviewable') && $up->isPreviewable())
            <img src="{{ $up->temporaryUrl() }}" alt="">
          @else
            <img src="{{ $urls[$slot] }}" alt="">
          @endif
        </div>
        <div class="br-ft">
          <b>{{ $meta[0] }}</b>
          <div class="h">{{ $meta[1] }}</div>
          <label class="br-file">Replace…<input type="file" wire:model="up.{{ $slot }}" accept="{{ collect(explode(',', $meta[2]))->map(fn ($e) => '.' . $e)->implode(',') }}"></label>
          <span class="br-new" wire:loading wire:target="up.{{ $slot }}">uploading…</span>
          @if($up)<span class="br-new">{{ $up->getClientOriginalName() }} — ready to save</span>@endif
          @error("up.$slot")<div style="color:#f87171;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
          @if($slot === 'icon_png' && ! $gd)
            <div style="color:#f0c46a;font-size:12px;margin-top:6px">This server can't resize images (GD missing), so the small sizes won't be made.</div>
          @endif
        </div>
      </div>
    @endforeach
  </div>

  <div class="br-used">
    <div>Marketing site<small>nav, footer, browser tab</small></div>
    <div>Emails from Intake<small>platform mail, billing notices</small></div>
    <div>Error pages<small>404, 500, maintenance</small></div>
    <div>Investor pages<small>proposal, portal</small></div>
    <div>Sign-in screens<small>staff login, setup, reset</small></div>
    <div>Shop pages<small>"Powered by Intake" and the default icon only</small></div>
  </div>

  @if($history)
    <div class="br-hist">
      <b>Previous versions</b>
      @foreach($history as $i => $h)
        <div class="r">
          <span>Saved {{ \Illuminate\Support\Carbon::parse($h['saved_at'] ?? now())->format('M j, Y g:ia') }}@if(!empty($h['by'])) by {{ $h['by'] }}@endif</span>
          <button type="button" class="br-btn" wire:click="restore({{ $i }})">Switch back</button>
        </div>
      @endforeach
    </div>
  @endif

  <div class="br-bar">
    <button type="button" class="br-btn" wire:click="discard" @disabled(empty(array_filter($this->up)))>Discard</button>
    <button type="button" class="br-btn br-btn--pri" wire:click="save" @disabled(empty(array_filter($this->up)))>Save</button>
  </div>
</div>
</x-filament-panels::page>
