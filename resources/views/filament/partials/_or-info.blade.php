{{-- MARKER-OUTREACH-SEND-DETAIL: an ⓘ that opens an explanation on click (works on a phone, unlike a hover tooltip). $label: text beside it, or null for the icon alone. $body: HTML. --}}
<span x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" style="position:relative;display:inline-flex;align-items:center;font-weight:400">
    <button type="button" @click="open = ! open" :aria-expanded="open" style="display:inline-flex;align-items:center;gap:6px;background:none;border:0;padding:0;color:var(--sx-dim);cursor:pointer;font-size:13px">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;border:1px solid currentColor;font-size:10.5px;font-weight:700;font-style:normal;line-height:1">i</span>
        @if ($label){{ $label }}@endif
    </button>
    <span x-show="open" x-cloak x-transition.opacity class="bg-white dark:bg-gray-900" style="position:absolute;top:calc(100% + 6px);left:0;z-index:30;width:min(520px,80vw);padding:12px 14px;border-radius:8px;border:1px solid var(--sx-line-2);box-shadow:0 12px 30px rgba(0,0,0,.35);font-size:13px;line-height:1.55;color:var(--sx-dim);text-transform:none;letter-spacing:0">{!! $body !!}</span>
</span>
