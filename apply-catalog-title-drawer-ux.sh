#!/bin/bash
# catalog-title-drawer-ux — three fixes to the review drawer.
#
#   1. Drawer opened mid-page instead of pinned to the right edge.
#      position:fixed resolves against the nearest ancestor that has a
#      transform/filter/perspective, not the viewport, and Filament's page
#      wrapper has one. No amount of inset-0 fixes that from inside. The
#      drawer is now @teleport('body'), which moves it out of the container
#      entirely — the same reason it stops being clipped by page scroll.
#
#   2. Attribute chips were inert text. They're buttons now: clicking
#      appends {attr:Name} to the template. Same for the standard tokens,
#      which had no affordance at all on this page.
#
#   3. Preview didn't move while typing. Bindings tightened to 250ms and
#      the preview block carries a wire:key plus a visible "recalculating"
#      state, so a slow round trip reads as pending rather than broken.
#      Row keys matter here: without them the DOM morph can keep stale text
#      when only the inner content changed.
# NO MIGRATION. Server: view:clear is enough (Blade + page class only).
set -e
if grep -q "MARKER-DRAWER-UX" resources/views/filament/pages/catalog-title-review.blade.php; then
  echo "catalog-title-drawer-ux already applied — aborting."; exit 1
fi

# ------------------------------------------------------------- page: addToken
python3 - <<'CTDU_0_EOF'
import io
p = 'app/Filament/Pages/CatalogTitleReview.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function closeDrawer(): void
    {
        $this->editingId = null;
    }"""
assert s.count(old) == 1, s.count(old)
new = """    public function closeDrawer(): void
    {
        $this->editingId = null;
    }

    /**
     * MARKER-DRAWER-UX \u2014 append a token to the template from a chip click.
     * Appending rather than inserting at the caret: Livewire doesn't know
     * where the caret is, and guessing produces worse results than putting
     * it on the end where it's visible and easy to drag.
     */
    public function addToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $this->tpl = trim(trim($this->tpl) . ' ' . $token);
    }

    /** The standard tokens, offered as chips alongside the attribute ones. */
    public function getBaseTokensProperty(): array
    {
        return ['{brand}', '{model}', '{size}', '{color}', '{unit}', '{type}', '{type0}', '{mpn}'];
    }"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('addToken ok')
CTDU_0_EOF

# ------------------------------------------------------------- view
python3 - <<'CTDU_1_EOF'
import io
p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- 1. teleport the drawer out of the transformed ancestor ---------------
old = """        <div class="fixed inset-0 bg-black/50 z-40" wire:click="closeDrawer"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-full max-w-2xl z-50 overflow-y-auto
                      bg-white dark:bg-gray-900 ring-1 ring-gray-950/10 dark:ring-white/10">"""
assert s.count(old) == 1, s.count(old)
new = """        {{-- MARKER-DRAWER-UX — teleported to body. position:fixed is contained by
             any ancestor with a transform, and Filament's page wrapper has one,
             which is why this opened mid-page instead of against the edge. --}}
        @teleport('body')
        <div wire:key="drawer-{{ $sc->id }}">
        <div class="fixed inset-0 bg-black/50 z-40" wire:click="closeDrawer"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-full max-w-2xl z-50 overflow-y-auto
                      bg-white dark:bg-gray-900 ring-1 ring-gray-950/10 dark:ring-white/10">"""
s = s.replace(old, new)

old = """        </aside>
    @endif

</x-filament-panels::page>"""
assert s.count(old) == 1
new = """        </aside>
        </div>
        @endteleport
    @endif

</x-filament-panels::page>"""
s = s.replace(old, new)

# --- 2. tighter binding on the two inputs --------------------------------
old = """                    <input wire:model.live.debounce.500ms="tpl"
                        class="w-full font-mono text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2.5">"""
assert s.count(old) == 1
new = """                    <input wire:model.live.debounce.250ms="tpl"
                        class="w-full font-mono text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2.5">"""
s = s.replace(old, new)

old = """                    <input wire:model.live.debounce.500ms="sizeAttr" placeholder="Labeled Size\""""
assert s.count(old) == 1
new = """                    <input wire:model.live.debounce.250ms="sizeAttr" placeholder="Labeled Size\""""
s = s.replace(old, new)

# --- 3. clickable tokens -------------------------------------------------
old = """                @if (count($this->attrNames))
                    <div>
                        <div class="text-xs font-semibold mb-2">Attributes on these items</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->attrNames as $n)
                                <span class="font-mono text-[11px] rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5">{{ '{attr:' . $n . '}' }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif"""
assert s.count(old) == 1
new = """                {{-- MARKER-DRAWER-UX — chips add themselves to the template. --}}
                <div>
                    <div class="text-xs font-semibold mb-2">Tokens — click to add</div>
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        @foreach ($this->baseTokens as $t)
                            <button type="button" wire:click="addToken(@js($t))"
                                class="font-mono text-[11px] rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5
                                       hover:ring-1 hover:ring-primary-500">{{ $t }}</button>
                        @endforeach
                    </div>

                    @if (count($this->attrNames))
                        <div class="text-xs font-semibold mb-2">Attributes on these items</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->attrNames as $n)
                                @php $tok = '{attr:' . $n . '}'; @endphp
                                <button type="button" wire:click="addToken(@js($tok))"
                                    class="font-mono text-[11px] rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5
                                           hover:ring-1 hover:ring-primary-500">{{ $tok }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>"""
s = s.replace(old, new)

# --- 4. preview: keys + pending state ------------------------------------
old = """                <div>
                    <div class="text-xs font-semibold mb-2">Preview — real items from this category</div>
                    <div class="rounded-lg ring-1 ring-gray-200 dark:ring-white/10 divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($this->preview as $p)
                            <div class="px-4 py-3">"""
assert s.count(old) == 1
new = """                <div>
                    <div class="text-xs font-semibold mb-2 flex items-center gap-2">
                        <span>Preview — real items from this category</span>
                        {{-- MARKER-DRAWER-UX — pending state, so a slow round trip
                             reads as working rather than stuck. --}}
                        <span wire:loading.delay wire:target="tpl,sizeAttr,addToken"
                              class="text-[10px] font-normal text-gray-400">recalculating…</span>
                    </div>
                    <div class="rounded-lg ring-1 ring-gray-200 dark:ring-white/10 divide-y divide-gray-100 dark:divide-white/5"
                         wire:loading.class="opacity-50" wire:target="tpl,sizeAttr,addToken">
                        @forelse ($this->preview as $p)
                            <div class="px-4 py-3" wire:key="prev-{{ $sc->id }}-{{ $loop->index }}">"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
CTDU_1_EOF

php -l app/Filament/Pages/CatalogTitleReview.php

echo
echo "catalog-title-drawer-ux applied."
