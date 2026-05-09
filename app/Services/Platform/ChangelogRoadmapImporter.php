<?php

namespace App\Services\Platform;

use App\Models\ChangelogEntry;
use App\Models\RoadmapEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a YAML import file into a Plan describing what will happen if
 * confirmed. Does NOT write to the database. The Plan is then handed to
 * the preview UI; on confirm, ::commit() applies it.
 *
 * Match key for "duplicate" detection:
 *   - Changelog: shipped_on (date) + title (case-insensitive)
 *   - Roadmap:   status + title (case-insensitive)
 *
 * Skipped duplicates: matched + body/category/etc. all identical → skip silently.
 * Updates: matched + something differs → flagged for review (preview shows diff).
 */
class ChangelogRoadmapImporter
{
    public const KIND_CHANGELOG = 'changelog';
    public const KIND_ROADMAP   = 'roadmap';

    public const VALID_CHANGELOG_CATEGORIES = [
        'Calendar', 'Booking', 'Stripe', 'Customer', 'Workflow', 'Bugfix', 'Polish',
    ];

    public const VALID_ROADMAP_STATUSES = [
        'shipped', 'in_progress', 'next_up', 'considering',
    ];

    /**
     * @return array{
     *   kind: string,
     *   new: array<int, array<string,mixed>>,
     *   updates: array<int, array{existing: array<string,mixed>, incoming: array<string,mixed>, diff: array<string,array{old:mixed,new:mixed}>}>,
     *   skipped: array<int, array<string,mixed>>,
     *   errors: array<int, array{line: ?int, message: string, raw: ?array<string,mixed>}>,
     * }
     */
    public function parse(string $kind, string $yamlContent): array
    {
        $plan = [
            'kind'    => $kind,
            'new'     => [],
            'updates' => [],
            'skipped' => [],
            'errors'  => [],
        ];

        // Top-level YAML parse.
        try {
            $parsed = Yaml::parse($yamlContent);
        } catch (ParseException $e) {
            $plan['errors'][] = [
                'line'    => $e->getParsedLine() ?: null,
                'message' => 'YAML parse error: ' . $e->getMessage(),
                'raw'     => null,
            ];
            return $plan;
        }

        if (! is_array($parsed)) {
            $plan['errors'][] = [
                'line'    => null,
                'message' => 'Top-level YAML must be a list of entries.',
                'raw'     => null,
            ];
            return $plan;
        }

        // The file may be a flat list, or wrapped in `entries:` for clarity.
        $entries = isset($parsed['entries']) && is_array($parsed['entries'])
            ? $parsed['entries']
            : $parsed;

        if (! is_array($entries) || empty($entries)) {
            $plan['errors'][] = [
                'line'    => null,
                'message' => 'No entries found in the file.',
                'raw'     => null,
            ];
            return $plan;
        }

        foreach ($entries as $idx => $entry) {
            if (! is_array($entry)) {
                $plan['errors'][] = [
                    'line'    => null,
                    'message' => 'Entry #' . ($idx + 1) . ' is not an object.',
                    'raw'     => null,
                ];
                continue;
            }

            $result = $kind === self::KIND_CHANGELOG
                ? $this->classifyChangelog($entry, $idx + 1)
                : $this->classifyRoadmap($entry, $idx + 1);

            $plan[$result['bucket']][] = $result['payload'];
        }

        return $plan;
    }

    /**
     * Apply a previously-parsed plan. Writes new + selected updates in a
     * single transaction. Returns counts.
     *
     * @param array<string,mixed> $plan          The Plan from ::parse()
     * @param array<int,bool>     $newSelections Indexes (into $plan['new'])     allowed to import
     * @param array<int,bool>     $updateSelections Indexes (into $plan['updates']) allowed to apply
     */
    public function commit(array $plan, array $newSelections, array $updateSelections): array
    {
        $kind     = $plan['kind'];
        $created  = 0;
        $updated  = 0;

        \DB::transaction(function () use ($plan, $newSelections, $updateSelections, $kind, &$created, &$updated) {
            foreach ($plan['new'] as $i => $payload) {
                if (empty($newSelections[$i])) continue;
                $kind === self::KIND_CHANGELOG
                    ? ChangelogEntry::create($this->stripMetaForCreate($payload, $kind))
                    : RoadmapEntry::create($this->stripMetaForCreate($payload, $kind));
                $created++;
            }
            foreach ($plan['updates'] as $i => $row) {
                if (empty($updateSelections[$i])) continue;
                $existing = $kind === self::KIND_CHANGELOG
                    ? ChangelogEntry::find($row['existing']['id'])
                    : RoadmapEntry::find($row['existing']['id']);
                if (! $existing) continue;
                $existing->fill($this->stripMetaForCreate($row['incoming'], $kind));
                $existing->save();
                $updated++;
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal: classify a single changelog entry
    // ──────────────────────────────────────────────────────────────────────
    private function classifyChangelog(array $entry, int $entryNum): array
    {
        // Required fields
        $missing = [];
        foreach (['date', 'title', 'body'] as $req) {
            if (empty($entry[$req])) $missing[] = $req;
        }
        if ($missing) {
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => "Entry #{$entryNum}: missing required field(s): " . implode(', ', $missing),
                'raw'     => $entry,
            ]];
        }

        // Date parse — Symfony YAML may hand us a string, int (epoch), or DateTime.
        $rawDate = $entry['date'];
        try {
            if ($rawDate instanceof \DateTimeInterface) {
                $shippedOn = Carbon::instance($rawDate)->toDateString();
            } elseif (is_int($rawDate)) {
                // YAML auto-parsed an unquoted ISO date as an epoch timestamp.
                $shippedOn = Carbon::createFromTimestampUTC($rawDate)->toDateString();
            } else {
                $shippedOn = Carbon::parse((string) $rawDate)->toDateString();
            }
        } catch (\Throwable $e) {
            $display = is_scalar($rawDate) ? (string) $rawDate : gettype($rawDate);
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => "Entry #{$entryNum}: invalid date '{$display}'.",
                'raw'     => $entry,
            ]];
        }

        // Category validation (optional field, but if set must be in the list)
        $category = isset($entry['category']) ? (string) $entry['category'] : null;
        if ($category !== null && $category !== '' && ! in_array($category, self::VALID_CHANGELOG_CATEGORIES, true)) {
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => "Entry #{$entryNum}: invalid category '{$category}'. Valid: " . implode(', ', self::VALID_CHANGELOG_CATEGORIES),
                'raw'     => $entry,
            ]];
        }

        $incoming = [
            'shipped_on'     => $shippedOn,
            'title'          => Str::limit((string) $entry['title'], 191, ''),
            'category'       => $category ?: null,
            'body'           => (string) $entry['body'],
            'is_published'   => false,                          // drafts by default; preview is approval
            'is_highlighted' => (bool) ($entry['highlight'] ?? false),
        ];

        // Duplicate check: shipped_on + title (case-insensitive)
        $existingModel = ChangelogEntry::query()
            ->whereDate('shipped_on', $shippedOn)
            ->whereRaw('LOWER(title) = ?', [Str::lower($incoming['title'])])
            ->first();

        if (! $existingModel) {
            return ['bucket' => 'new', 'payload' => array_merge($incoming, [
                '_entry_num' => $entryNum,
            ])];
        }

        $existing = [
            'id'             => $existingModel->id,
            'shipped_on'     => $existingModel->shipped_on?->toDateString(),
            'title'          => $existingModel->title,
            'category'       => $existingModel->category,
            'body'           => $existingModel->body,
            'is_published'   => $existingModel->is_published,
            'is_highlighted' => $existingModel->is_highlighted,
        ];

        $diff = $this->diff($existing, $incoming, ['category', 'body', 'is_highlighted']);

        if (empty($diff)) {
            return ['bucket' => 'skipped', 'payload' => array_merge($incoming, [
                '_entry_num' => $entryNum,
                '_existing_id' => $existingModel->id,
            ])];
        }

        return ['bucket' => 'updates', 'payload' => [
            'existing'   => $existing,
            'incoming'   => array_merge($incoming, ['_entry_num' => $entryNum]),
            'diff'       => $diff,
        ]];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal: classify a single roadmap entry
    // ──────────────────────────────────────────────────────────────────────
    private function classifyRoadmap(array $entry, int $entryNum): array
    {
        $missing = [];
        foreach (['status', 'title', 'body'] as $req) {
            if (empty($entry[$req])) $missing[] = $req;
        }
        if ($missing) {
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => "Entry #{$entryNum}: missing required field(s): " . implode(', ', $missing),
                'raw'     => $entry,
            ]];
        }

        $status = (string) $entry['status'];
        if (! in_array($status, self::VALID_ROADMAP_STATUSES, true)) {
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => "Entry #{$entryNum}: invalid status '{$status}'. Valid: " . implode(', ', self::VALID_ROADMAP_STATUSES),
                'raw'     => $entry,
            ]];
        }

        $incoming = [
            'status'          => $status,
            'title'           => Str::limit((string) $entry['title'], 191, ''),
            'category'        => isset($entry['category']) ? (string) $entry['category'] : null,
            'body'            => (string) $entry['body'],
            'rough_timeframe' => isset($entry['timeframe']) ? Str::limit((string) $entry['timeframe'], 64, '') : null,
            'display_order'   => (int) ($entry['order'] ?? 0),
            'is_published'    => false,
        ];

        $existingModel = RoadmapEntry::query()
            ->where('status', $status)
            ->whereRaw('LOWER(title) = ?', [Str::lower($incoming['title'])])
            ->first();

        if (! $existingModel) {
            return ['bucket' => 'new', 'payload' => array_merge($incoming, [
                '_entry_num' => $entryNum,
            ])];
        }

        $existing = [
            'id'              => $existingModel->id,
            'status'          => $existingModel->status,
            'title'           => $existingModel->title,
            'category'        => $existingModel->category,
            'body'            => $existingModel->body,
            'rough_timeframe' => $existingModel->rough_timeframe,
            'display_order'   => $existingModel->display_order,
            'is_published'    => $existingModel->is_published,
        ];

        $diff = $this->diff($existing, $incoming, ['category', 'body', 'rough_timeframe', 'display_order']);

        if (empty($diff)) {
            return ['bucket' => 'skipped', 'payload' => array_merge($incoming, [
                '_entry_num'   => $entryNum,
                '_existing_id' => $existingModel->id,
            ])];
        }

        return ['bucket' => 'updates', 'payload' => [
            'existing' => $existing,
            'incoming' => array_merge($incoming, ['_entry_num' => $entryNum]),
            'diff'     => $diff,
        ]];
    }

    private function diff(array $existing, array $incoming, array $compareKeys): array
    {
        $diff = [];
        foreach ($compareKeys as $key) {
            $a = $existing[$key] ?? null;
            $b = $incoming[$key] ?? null;
            // Normalize string trim to avoid trailing-newline noise.
            if (is_string($a)) $a = rtrim($a);
            if (is_string($b)) $b = rtrim($b);
            if ($a != $b) {
                $diff[$key] = ['old' => $a, 'new' => $b];
            }
        }
        return $diff;
    }

    /** Strip preview-only metadata before insert/update. */
    private function stripMetaForCreate(array $payload, string $kind): array
    {
        unset($payload['_entry_num'], $payload['_existing_id']);
        return $payload;
    }
}
