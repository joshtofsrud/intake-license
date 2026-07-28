#!/bin/bash
# catalog-description-preview — try Claude descriptions without committing.
#   Adds a "Preview Claude descriptions" button to the review drawer. It
#   generates copy for the scope's five sample items and shows it inline.
#
#   It writes NOTHING. No column, no catalog update, no recompose. The point
#   is to see real output on real HLC rows for a category you pick, decide
#   whether it's good enough, and only then talk about where it lands.
#   Cost per press is about a tenth of a cent.
#
#   Shape of the call: all five items go in ONE request returning a JSON
#   array, rather than five requests. Cheaper, one round trip, and the model
#   sees the set together so it doesn't open all five the same way.
#
#   The prompt is deliberately constrained to the attributes on the row.
#   It is told not to invent specs and not to use marketing language — a
#   description that claims a tire is "confidence-inspiring" is worse than
#   no description, because a shop will read it to a customer.
#
#   Sparse rows are skipped rather than guessed at: under three attributes
#   there is nothing to write from, and the preview says so instead of
#   producing filler.
# NO MIGRATION. Requires ANTHROPIC_API_KEY (already set — onboarding uses it).
# Server: optimize:clear.
set -e
if [ -f app/Services/Distributors/CatalogDescriptionService.php ]; then
  echo "catalog-description-preview already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ service
cat > 'app/Services/Distributors/CatalogDescriptionService.php' <<'CDP_0_EOF'
<?php

// MARKER-DESC-PREVIEW

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;
use App\Services\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Log;

/**
 * Generates short factual descriptions for distributor catalog rows.
 *
 * Preview only for now — nothing here persists anything. Callers get the
 * text back and decide what to do with it.
 */
class CatalogDescriptionService
{
    /** Haiku: this is high-volume, low-judgement work. */
    public const MODEL = 'claude-haiku-4-5';

    /** Below this many attributes there is nothing to write from. */
    public const MIN_ATTRIBUTES = 3;

    private const SYSTEM = <<<'TXT'
You write short product descriptions for a bicycle shop's point-of-sale system.

Rules, in order of importance:
1. Use ONLY the attributes given for each item. Never state a spec that is not
   in the data. If you are unsure what an attribute means, leave it out.
2. No marketing language. No "confidence-inspiring", "game-changing",
   "premium", "perfect for". Shop staff read these aloud to customers and
   will be held to them.
3. Two sentences maximum, under 30 words total. These display in a list.
4. Lead with what the item IS and its defining size or fitment, then the one
   or two attributes that would actually change a buying decision.
5. Plain declarative sentences. No exclamation marks.

Return a JSON array only — no prose, no markdown fence. Each element:
{"id": "<the id given>", "description": "<your text>"}
TXT;

    public function __construct(private AnthropicClient $client) {}

    /**
     * @param  \Illuminate\Support\Collection<int,PlatformDistributorCatalog> $rows
     * @return array<int,array{id:string,name:string,description:?string,skipped:?string}>
     */
    public function preview($rows): array
    {
        $usable = [];
        $out    = [];

        foreach ($rows as $row) {
            $attrs = $this->attrPairs($row);
            if (count($attrs) < self::MIN_ATTRIBUTES) {
                // Say so rather than generating filler from nothing.
                $out[(string) $row->id] = [
                    'id'          => (string) $row->id,
                    'name'        => (string) ($row->display_name ?: $row->name),
                    'description' => null,
                    'skipped'     => 'only ' . count($attrs) . ' attributes on this row',
                ];
                continue;
            }

            $usable[] = [
                'id'         => (string) $row->id,
                'name'       => (string) $row->name,
                'brand'      => (string) $row->manufacturer,
                'category'   => (string) $row->category_path,
                'attributes' => $attrs,
            ];

            $out[(string) $row->id] = [
                'id'          => (string) $row->id,
                'name'        => (string) ($row->display_name ?: $row->name),
                'description' => null,
                'skipped'     => null,
            ];
        }

        if (! $usable) {
            return array_values($out);
        }

        try {
            $response = $this->client->messages(
                self::MODEL,
                1000,
                [['role' => 'user', 'content' => json_encode($usable, JSON_UNESCAPED_SLASHES)]],
                ['system' => self::SYSTEM, 'temperature' => 0.2]
            );

            $text = trim($this->client->extractText($response));
            // Defensive: strip a fence if one turns up despite the instruction.
            $text = preg_replace('/^```(?:json)?|```$/m', '', $text);

            foreach ((json_decode(trim((string) $text), true) ?: []) as $item) {
                $id = (string) ($item['id'] ?? '');
                if ($id !== '' && isset($out[$id])) {
                    $out[$id]['description'] = trim((string) ($item['description'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('catalog_description.preview_failed', ['error' => $e->getMessage()]);
            foreach ($out as $id => $_) {
                if ($out[$id]['skipped'] === null) {
                    $out[$id]['skipped'] = 'generation failed — ' . $e->getMessage();
                }
            }
        }

        return array_values($out);
    }

    /** Name => Value pairs with a value present. */
    private function attrPairs(PlatformDistributorCatalog $row): array
    {
        $pairs = [];
        foreach (($row->attributes ?? []) as $a) {
            if (is_array($a) && isset($a['Name']) && trim((string) ($a['Value'] ?? '')) !== '') {
                $pairs[trim((string) $a['Name'])] = trim((string) $a['Value']);
            }
        }
        return $pairs;
    }
}
CDP_0_EOF

# ------------------------------------------------------------------ page
python3 - <<'CDP_1_EOF'
import io
p = 'app/Filament/Pages/CatalogTitleReview.php'
s = io.open(p, encoding='utf-8').read()

old = """    public bool $queueMode = false;"""
assert s.count(old) == 1, s.count(old)
new = """    public bool $queueMode = false;

    /** MARKER-DESC-PREVIEW \u2014 generated copy, held in memory only. */
    public array $descPreview = [];"""
s = s.replace(old, new)

old = """    public function closeDrawer(): void
    {
        $this->editingId = null;
    }"""
assert s.count(old) == 1
new = """    public function closeDrawer(): void
    {
        $this->editingId = null;
        $this->descPreview = [];
    }

    /**
     * MARKER-DESC-PREVIEW \u2014 generate descriptions for this scope's samples.
     * Nothing is written; the result lives on the component until the drawer
     * closes. This is here to answer "is the output any good" before any
     * decision about storing it.
     */
    public function previewDescriptions(): void
    {
        $scope = $this->editing;
        if (! $scope) {
            return;
        }

        $rows = PlatformDistributorCatalog::whereIn('id', $scope->sample_ids ?? [])->get();
        if ($rows->isEmpty()) {
            Notification::make()->warning()->title('No sample items stored')
                ->body('Re-run catalog:scan-titles for this distributor.')->send();
            return;
        }

        try {
            $this->descPreview = app(\\App\\Services\\Distributors\\CatalogDescriptionService::class)
                ->preview($rows);
        } catch (\\Throwable $e) {
            // A missing API key throws in the client constructor, so it
            // surfaces here rather than as a 500.
            Notification::make()->danger()->title('Could not generate')
                ->body($e->getMessage())->send();
        }
    }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('page ok')
CDP_1_EOF

# ------------------------------------------------------------------ view
python3 - <<'CDP_2_EOF'
import io
p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                <div>
                    <div class="text-xs font-semibold mb-2 flex items-center gap-2">
                        <span>Preview — real items from this category</span>"""
assert s.count(old) == 1, s.count(old)
new = """                {{-- MARKER-DESC-PREVIEW — generated copy, nothing saved. --}}
                <div>
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <span class="text-xs font-semibold">Claude descriptions</span>
                        <button type="button" wire:click="previewDescriptions"
                            wire:loading.attr="disabled" wire:target="previewDescriptions"
                            class="text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
                            <span wire:loading.remove wire:target="previewDescriptions">Preview on 5 items</span>
                            <span wire:loading wire:target="previewDescriptions">Generating…</span>
                        </button>
                    </div>

                    @if (count($descPreview))
                        <div class="rounded-lg ring-1 ring-gray-200 dark:ring-white/10 divide-y divide-gray-100 dark:divide-white/5 mb-2">
                            @foreach ($descPreview as $d)
                                <div class="px-4 py-3">
                                    <div class="text-[11px] text-gray-400">{{ $d['name'] }}</div>
                                    @if ($d['description'])
                                        <div class="text-sm mt-1">{{ $d['description'] }}</div>
                                    @else
                                        <div class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                            skipped — {{ $d['skipped'] }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-gray-400 mb-4">
                            Nothing saved. This is a look at what generating for all
                            {{ number_format($sc->item_count) }} items would produce.
                        </p>
                    @else
                        <p class="text-[11px] text-gray-400 mb-4">
                            Generates copy from the attributes on this category's items.
                            Costs about a tenth of a cent and saves nothing.
                        </p>
                    @endif
                </div>

                <div>
                    <div class="text-xs font-semibold mb-2 flex items-center gap-2">
                        <span>Preview — real items from this category</span>"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
CDP_2_EOF

php -l app/Services/Distributors/CatalogDescriptionService.php
php -l app/Filament/Pages/CatalogTitleReview.php

echo
echo "catalog-description-preview applied."
