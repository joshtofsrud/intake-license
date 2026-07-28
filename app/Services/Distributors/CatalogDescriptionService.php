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
