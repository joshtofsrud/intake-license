#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Intake — Changelog & Roadmap Importer
# Adds "Import from file" header action to Filament ChangelogEntryResource
# and RoadmapEntryResource list pages. Preview-then-confirm workflow.
#
# Usage on server (cwd = /var/www/intake):
#   bash intake-changelog-importer-patch.sh
#   php artisan optimize:clear
#   systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm
#
# Idempotent: re-running is safe. Existing files are checked before patching.
# All edits use python heredocs with s.count(old) == 1 assertions.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# Sanity check we're in the right directory.
[ -f artisan ] || { echo "ABORT: not a Laravel root (no artisan file)"; exit 1; }
[ -f app/Filament/Resources/ChangelogEntryResource.php ] || { echo "ABORT: ChangelogEntryResource.php missing"; exit 1; }
[ -f app/Filament/Resources/RoadmapEntryResource.php ] || { echo "ABORT: RoadmapEntryResource.php missing"; exit 1; }

echo "==> Creating service classes"

# ──────────────────────────────────────────────────────────────────────────────
# 1. The importer service — parses YAML, builds a Plan, no DB writes.
# ──────────────────────────────────────────────────────────────────────────────
mkdir -p app/Services/Platform

cat > app/Services/Platform/ChangelogRoadmapImporter.php <<'PHP_FILE'
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

        // Date parse
        try {
            $shippedOn = Carbon::parse((string) $entry['date'])->toDateString();
        } catch (\Throwable $e) {
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => "Entry #{$entryNum}: invalid date '{$entry['date']}'.",
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
PHP_FILE

echo "    wrote: app/Services/Platform/ChangelogRoadmapImporter.php"

# ──────────────────────────────────────────────────────────────────────────────
# 2. The custom Filament Page that shows the preview and handles confirm
# ──────────────────────────────────────────────────────────────────────────────
mkdir -p app/Filament/Pages

cat > app/Filament/Pages/ChangelogImportPreview.php <<'PHP_FILE'
<?php

namespace App\Filament\Pages;

use App\Services\Platform\ChangelogRoadmapImporter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Preview page for changelog/roadmap YAML imports.
 *
 * Lifecycle:
 *   1. User uploads file via Filament header action on ListChangelogEntries
 *      or ListRoadmapEntries.
 *   2. Action stores the uploaded file path + parsed plan in cache under a
 *      random token, then redirects to this page with ?token={token}.
 *   3. This page renders the parsed plan with per-row checkboxes.
 *   4. On "Import" submit, the page calls ChangelogRoadmapImporter::commit()
 *      with the user's selections, flushes the cache, and redirects back.
 *   5. On "Re-upload" submit, page accepts a fresh file, re-parses, replaces
 *      the cached plan, and re-renders.
 *   6. On "Cancel" submit, the cache is flushed and we redirect back.
 */
class ChangelogImportPreview extends Page
{
    protected static string $view = 'filament.pages.changelog-import-preview';

    /** Hidden from main nav — only reached via redirect from list-page action. */
    protected static bool $shouldRegisterNavigation = false;

    /** URL slug. Filament will register this as /admin/changelog-import-preview */
    protected static ?string $slug = 'changelog-import-preview';

    public ?string $token = null;
    public ?array $plan = null;
    public array $newSelections = [];
    public array $updateSelections = [];
    public ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $reuploadFile = null;

    public function mount(): void
    {
        $this->token = (string) request('token', '');
        if (! $this->token) {
            Notification::make()->title('No import token in URL.')->danger()->send();
            $this->redirect($this->backUrl(self::KIND_CHANGELOG_DEFAULT));
            return;
        }

        $cached = Cache::get($this->cacheKey());
        if (! $cached) {
            Notification::make()->title('Import session expired.')->body('Please re-upload your file.')->warning()->send();
            $this->redirect($this->backUrl(self::KIND_CHANGELOG_DEFAULT));
            return;
        }

        $this->plan = $cached['plan'];

        // Pre-select everything by default; user can deselect.
        foreach (($this->plan['new'] ?? []) as $i => $_) {
            $this->newSelections[$i] = true;
        }
        foreach (($this->plan['updates'] ?? []) as $i => $_) {
            $this->updateSelections[$i] = true;
        }
    }

    public function getTitle(): string
    {
        $kind = $this->plan['kind'] ?? '';
        return $kind === ChangelogRoadmapImporter::KIND_ROADMAP
            ? 'Preview roadmap import'
            : 'Preview changelog import';
    }

    public function commitImport(): void
    {
        if (! $this->plan) return;

        $importer = app(ChangelogRoadmapImporter::class);
        $result = $importer->commit($this->plan, $this->newSelections, $this->updateSelections);

        // Drop the cached plan + temp file
        $cached = Cache::get($this->cacheKey());
        if ($cached && ! empty($cached['file_path']) && Storage::disk('local')->exists($cached['file_path'])) {
            Storage::disk('local')->delete($cached['file_path']);
        }
        Cache::forget($this->cacheKey());

        Notification::make()
            ->title('Import complete')
            ->body("{$result['created']} created · {$result['updated']} updated · all imported as drafts (unpublished).")
            ->success()
            ->send();

        $this->redirect($this->backUrl($this->plan['kind']));
    }

    public function cancelImport(): void
    {
        $kind = $this->plan['kind'] ?? self::KIND_CHANGELOG_DEFAULT;
        $cached = Cache::get($this->cacheKey());
        if ($cached && ! empty($cached['file_path']) && Storage::disk('local')->exists($cached['file_path'])) {
            Storage::disk('local')->delete($cached['file_path']);
        }
        Cache::forget($this->cacheKey());

        Notification::make()->title('Import cancelled.')->send();
        $this->redirect($this->backUrl($kind));
    }

    public function reupload(): void
    {
        if (! $this->reuploadFile) {
            Notification::make()->title('No file selected.')->warning()->send();
            return;
        }
        if (! $this->plan) return;

        $kind = $this->plan['kind'];
        $contents = file_get_contents($this->reuploadFile->getRealPath());

        $importer = app(ChangelogRoadmapImporter::class);
        $newPlan = $importer->parse($kind, $contents);

        // Persist new file + plan under same token
        $relPath = "changelog-imports/{$this->token}.yml";
        Storage::disk('local')->put($relPath, $contents);

        Cache::put($this->cacheKey(), [
            'plan'      => $newPlan,
            'file_path' => $relPath,
            'kind'      => $kind,
        ], now()->addMinutes(30));

        $this->plan = $newPlan;
        $this->newSelections = [];
        $this->updateSelections = [];
        foreach (($this->plan['new'] ?? []) as $i => $_) $this->newSelections[$i] = true;
        foreach (($this->plan['updates'] ?? []) as $i => $_) $this->updateSelections[$i] = true;

        $this->reuploadFile = null;

        Notification::make()->title('File re-parsed.')->success()->send();
    }

    private const KIND_CHANGELOG_DEFAULT = 'changelog';

    private function cacheKey(): string
    {
        return "changelog_import_plan:{$this->token}";
    }

    private function backUrl(string $kind): string
    {
        return $kind === ChangelogRoadmapImporter::KIND_ROADMAP
            ? route('filament.admin.resources.roadmap-entries.index')
            : route('filament.admin.resources.changelog.index');
    }
}
PHP_FILE

echo "    wrote: app/Filament/Pages/ChangelogImportPreview.php"

# ──────────────────────────────────────────────────────────────────────────────
# 3. The blade view for the preview page
# ──────────────────────────────────────────────────────────────────────────────
mkdir -p resources/views/filament/pages

cat > resources/views/filament/pages/changelog-import-preview.blade.php <<'BLADE_FILE'
<x-filament-panels::page>
    @php
        $kind = $plan['kind'] ?? 'changelog';
        $isRoadmap = $kind === \App\Services\Platform\ChangelogRoadmapImporter::KIND_ROADMAP;
        $newCount = count($plan['new'] ?? []);
        $updCount = count($plan['updates'] ?? []);
        $skipCount = count($plan['skipped'] ?? []);
        $errCount = count($plan['errors'] ?? []);
    @endphp

    <div class="space-y-6">

        {{-- Summary bar --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-emerald-300">New</div>
                <div class="text-2xl font-semibold text-emerald-100">{{ $newCount }}</div>
            </div>
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-amber-300">Updates</div>
                <div class="text-2xl font-semibold text-amber-100">{{ $updCount }}</div>
            </div>
            <div class="rounded-lg border border-zinc-500/30 bg-zinc-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-zinc-400">Skipped (no change)</div>
                <div class="text-2xl font-semibold text-zinc-300">{{ $skipCount }}</div>
            </div>
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/5 p-4">
                <div class="text-xs uppercase tracking-wide text-rose-300">Errors</div>
                <div class="text-2xl font-semibold text-rose-100">{{ $errCount }}</div>
            </div>
        </div>

        <div class="text-sm text-zinc-400">
            All imported entries default to <strong class="text-zinc-200">drafts (unpublished)</strong>.
            You'll publish them manually from the {{ $isRoadmap ? 'roadmap' : 'changelog' }} list once you've reviewed.
        </div>

        {{-- Errors --}}
        @if ($errCount > 0)
            <div class="rounded-lg border border-rose-500/40 bg-rose-500/5 p-4 space-y-2">
                <div class="font-semibold text-rose-200">Errors — these will not be imported</div>
                <ul class="space-y-2 text-sm text-rose-100/90">
                    @foreach ($plan['errors'] as $err)
                        <li class="border-l-2 border-rose-500/40 pl-3">
                            @if (!empty($err['line']))
                                <span class="text-rose-300">Line {{ $err['line'] }}:</span>
                            @endif
                            {{ $err['message'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- New entries --}}
        @if ($newCount > 0)
            <div class="space-y-3">
                <h2 class="text-lg font-semibold text-zinc-100">New entries ({{ $newCount }})</h2>
                <div class="space-y-2">
                    @foreach ($plan['new'] as $i => $row)
                        <label class="block rounded-lg border border-zinc-700 bg-zinc-900/40 p-4 cursor-pointer hover:border-emerald-500/50">
                            <div class="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model="newSelections.{{ $i }}"
                                    class="mt-1 h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-emerald-500 focus:ring-emerald-500"
                                />
                                <div class="flex-1 space-y-1">
                                    <div class="flex items-center gap-2 text-xs text-zinc-400">
                                        @if ($isRoadmap)
                                            <span class="rounded bg-zinc-800 px-2 py-0.5 text-zinc-200">{{ $row['status'] }}</span>
                                            @if (!empty($row['rough_timeframe']))
                                                <span>· {{ $row['rough_timeframe'] }}</span>
                                            @endif
                                        @else
                                            <span>{{ $row['shipped_on'] }}</span>
                                            @if (!empty($row['category']))
                                                <span>· <span class="rounded bg-zinc-800 px-2 py-0.5 text-zinc-200">{{ $row['category'] }}</span></span>
                                            @endif
                                            @if (!empty($row['is_highlighted']))
                                                <span class="rounded bg-lime-500/20 px-2 py-0.5 text-lime-200">Highlighted</span>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="font-medium text-zinc-100">{{ $row['title'] }}</div>
                                    <div class="text-sm text-zinc-300 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($row['body'], 400) }}</div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Updates --}}
        @if ($updCount > 0)
            <div class="space-y-3">
                <h2 class="text-lg font-semibold text-zinc-100">Updates to existing entries ({{ $updCount }})</h2>
                <div class="space-y-2">
                    @foreach ($plan['updates'] as $i => $row)
                        <label class="block rounded-lg border border-zinc-700 bg-zinc-900/40 p-4 cursor-pointer hover:border-amber-500/50">
                            <div class="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model="updateSelections.{{ $i }}"
                                    class="mt-1 h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-amber-500 focus:ring-amber-500"
                                />
                                <div class="flex-1 space-y-2">
                                    <div class="font-medium text-zinc-100">{{ $row['incoming']['title'] }}</div>
                                    <div class="space-y-2">
                                        @foreach ($row['diff'] as $field => $vals)
                                            <div class="rounded border border-zinc-700 bg-zinc-900 p-2 text-xs">
                                                <div class="text-zinc-400 uppercase tracking-wide">{{ $field }}</div>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-1">
                                                    <div>
                                                        <div class="text-rose-300 text-[10px] uppercase">old</div>
                                                        <div class="text-rose-100 whitespace-pre-line">{{ is_bool($vals['old']) ? ($vals['old'] ? 'true' : 'false') : (string) $vals['old'] }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-emerald-300 text-[10px] uppercase">new</div>
                                                        <div class="text-emerald-100 whitespace-pre-line">{{ is_bool($vals['new']) ? ($vals['new'] ? 'true' : 'false') : (string) $vals['new'] }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Skipped --}}
        @if ($skipCount > 0)
            <details class="rounded-lg border border-zinc-700 bg-zinc-900/40 p-4">
                <summary class="cursor-pointer text-sm font-medium text-zinc-300">
                    Skipped — {{ $skipCount }} entries already match the database (no changes)
                </summary>
                <ul class="mt-3 space-y-1 text-sm text-zinc-400">
                    @foreach ($plan['skipped'] as $row)
                        <li>· {{ $row['title'] }} <span class="text-zinc-500">({{ $isRoadmap ? $row['status'] : $row['shipped_on'] }})</span></li>
                    @endforeach
                </ul>
            </details>
        @endif

        {{-- Action bar --}}
        <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-6">
            <x-filament::button
                wire:click="commitImport"
                wire:confirm="Import the selected entries as drafts?"
                :disabled="$newCount === 0 && $updCount === 0"
                color="success"
            >
                Import selected
            </x-filament::button>

            <x-filament::button wire:click="cancelImport" color="gray">
                Cancel
            </x-filament::button>

            <div class="ml-auto flex items-center gap-2">
                <input
                    type="file"
                    wire:model="reuploadFile"
                    accept=".yml,.yaml"
                    class="text-sm text-zinc-300 file:mr-2 file:rounded file:border-0 file:bg-zinc-800 file:px-3 file:py-1.5 file:text-zinc-200 hover:file:bg-zinc-700"
                />
                <x-filament::button wire:click="reupload" color="warning" size="sm">
                    Re-parse uploaded file
                </x-filament::button>
            </div>
        </div>

    </div>
</x-filament-panels::page>
BLADE_FILE

echo "    wrote: resources/views/filament/pages/changelog-import-preview.blade.php"

echo "==> Patching list pages with header actions"

# ──────────────────────────────────────────────────────────────────────────────
# 4. Patch ListChangelogEntries to add the import action
# ──────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path("app/Filament/Resources/ChangelogEntryResource/Pages/ListChangelogEntries.php")
s = p.read_text()

old = """<?php

namespace App\\Filament\\Resources\\ChangelogEntryResource\\Pages;

use App\\Filament\\Resources\\ChangelogEntryResource;
use Filament\\Actions;
use Filament\\Resources\\Pages\\ListRecords;

class ListChangelogEntries extends ListRecords
{
    protected static string $resource = ChangelogEntryResource::class;
    protected function getHeaderActions(): array { return [Actions\\CreateAction::make()]; }
}
"""

new = """<?php

namespace App\\Filament\\Resources\\ChangelogEntryResource\\Pages;

use App\\Filament\\Resources\\ChangelogEntryResource;
use App\\Services\\Platform\\ChangelogRoadmapImporter;
use Filament\\Actions;
use Filament\\Forms;
use Filament\\Notifications\\Notification;
use Filament\\Resources\\Pages\\ListRecords;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Support\\Str;

class ListChangelogEntries extends ListRecords
{
    protected static string $resource = ChangelogEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\\Action::make('importFromFile')
                ->label('Import from file')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import changelog entries from YAML')
                ->modalDescription('Upload a .yml/.yaml file. You will see a preview before any rows are written.')
                ->modalSubmitActionLabel('Parse file')
                ->form([
                    Forms\\Components\\FileUpload::make('file')
                        ->label('YAML file')
                        ->acceptedFileTypes(['application/x-yaml', 'text/yaml', 'text/x-yaml', 'text/plain'])
                        ->disk('local')
                        ->directory('changelog-imports/staging')
                        ->visibility('private')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $relPath = $data['file'];
                    $absPath = Storage::disk('local')->path($relPath);
                    if (! is_file($absPath)) {
                        Notification::make()->title('Upload failed')->danger()->send();
                        return;
                    }
                    $contents = file_get_contents($absPath);

                    $importer = app(ChangelogRoadmapImporter::class);
                    $plan = $importer->parse(ChangelogRoadmapImporter::KIND_CHANGELOG, $contents);

                    $token = (string) Str::uuid();
                    // Move file to canonical token-based path
                    $finalPath = \"changelog-imports/{$token}.yml\";
                    Storage::disk('local')->put($finalPath, $contents);
                    Storage::disk('local')->delete($relPath);

                    Cache::put(\"changelog_import_plan:{$token}\", [
                        'plan'      => $plan,
                        'file_path' => $finalPath,
                        'kind'      => ChangelogRoadmapImporter::KIND_CHANGELOG,
                    ], now()->addMinutes(30));

                    return redirect()->to(route('filament.admin.pages.changelog-import-preview', ['token' => $token]));
                }),

            Actions\\CreateAction::make(),
        ];
    }
}
"""

assert s.count(old) == 1, f"ABORT: ListChangelogEntries pattern not found exactly once (found {s.count(old)})"
p.write_text(s.replace(old, new))
print("    patched: ListChangelogEntries.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 5. Patch ListRoadmapEntries identically (just kind=ROADMAP)
# ──────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path("app/Filament/Resources/RoadmapEntryResource/Pages/ListRoadmapEntries.php")
s = p.read_text()

old = """<?php

namespace App\\Filament\\Resources\\RoadmapEntryResource\\Pages;

use App\\Filament\\Resources\\RoadmapEntryResource;
use Filament\\Actions;
use Filament\\Resources\\Pages\\ListRecords;

class ListRoadmapEntries extends ListRecords
{
    protected static string $resource = RoadmapEntryResource::class;
    protected function getHeaderActions(): array { return [Actions\\CreateAction::make()]; }
}
"""

new = """<?php

namespace App\\Filament\\Resources\\RoadmapEntryResource\\Pages;

use App\\Filament\\Resources\\RoadmapEntryResource;
use App\\Services\\Platform\\ChangelogRoadmapImporter;
use Filament\\Actions;
use Filament\\Forms;
use Filament\\Notifications\\Notification;
use Filament\\Resources\\Pages\\ListRecords;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Support\\Str;

class ListRoadmapEntries extends ListRecords
{
    protected static string $resource = RoadmapEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\\Action::make('importFromFile')
                ->label('Import from file')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import roadmap entries from YAML')
                ->modalDescription('Upload a .yml/.yaml file. You will see a preview before any rows are written.')
                ->modalSubmitActionLabel('Parse file')
                ->form([
                    Forms\\Components\\FileUpload::make('file')
                        ->label('YAML file')
                        ->acceptedFileTypes(['application/x-yaml', 'text/yaml', 'text/x-yaml', 'text/plain'])
                        ->disk('local')
                        ->directory('changelog-imports/staging')
                        ->visibility('private')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $relPath = $data['file'];
                    $absPath = Storage::disk('local')->path($relPath);
                    if (! is_file($absPath)) {
                        Notification::make()->title('Upload failed')->danger()->send();
                        return;
                    }
                    $contents = file_get_contents($absPath);

                    $importer = app(ChangelogRoadmapImporter::class);
                    $plan = $importer->parse(ChangelogRoadmapImporter::KIND_ROADMAP, $contents);

                    $token = (string) Str::uuid();
                    $finalPath = \"changelog-imports/{$token}.yml\";
                    Storage::disk('local')->put($finalPath, $contents);
                    Storage::disk('local')->delete($relPath);

                    Cache::put(\"changelog_import_plan:{$token}\", [
                        'plan'      => $plan,
                        'file_path' => $finalPath,
                        'kind'      => ChangelogRoadmapImporter::KIND_ROADMAP,
                    ], now()->addMinutes(30));

                    return redirect()->to(route('filament.admin.pages.changelog-import-preview', ['token' => $token]));
                }),

            Actions\\CreateAction::make(),
        ];
    }
}
"""

assert s.count(old) == 1, f"ABORT: ListRoadmapEntries pattern not found exactly once (found {s.count(old)})"
p.write_text(s.replace(old, new))
print("    patched: ListRoadmapEntries.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 6. Sample import files — last night's eight ships, ready to upload
# ──────────────────────────────────────────────────────────────────────────────
mkdir -p storage/changelog-import-samples

cat > storage/changelog-import-samples/changelog-2026-05-09.yml <<'YAML_FILE'
# Sample changelog import file for May 9, 2026 ships.
# Upload via Master Admin → Changelog → Import from file.
# All entries import as drafts; you publish manually after review.
#
# Required fields: date (YYYY-MM-DD), title, body
# Optional fields: category, highlight (true/false)
# Valid categories: Calendar, Booking, Stripe, Customer, Workflow, Bugfix, Polish

entries:
  - date: 2026-05-09
    title: Payment ledger Phase 1
    category: Stripe
    highlight: true
    body: |
      Every dollar now flows through the register and gets a ledger row.
      New tenant_appointment_payments table, AppointmentRegisterBridgeService,
      AppointmentPaymentService. Bridge auto-creates draft sales when
      appointments complete with a balance due. Refunds are method-aware
      and create signed ledger rows.

  - date: 2026-05-09
    title: tax_locked flag for externally-computed tax
    category: Stripe
    body: |
      Sales created by external integrations (appointment bridge, future
      Stripe Connect deposits) preserve their tax allocation through JS
      auto-saves and recalc. New tax_locked column with backfill for any
      existing appointment-sourced sales.

  - date: 2026-05-09
    title: Sale detail modal
    category: Workflow
    body: |
      Click any row in admin/register/history or in customer activity
      timeline to see the sale details — line items, totals, payment
      method, and a Refund this sale button if applicable.

  - date: 2026-05-09
    title: Settings page restyle
    category: Polish
    body: |
      Six-tab unified settings page replaces the old multi-page split.
      Business, Branding, Communication, Account, Appearance, Payments.
      Sticky save bar at the top of each panel with dirty-tracking.

  - date: 2026-05-09
    title: Logo size slider in Branding
    category: Polish
    body: |
      Drag to resize your logo in the admin header. Preview updates live;
      save when satisfied.

  - date: 2026-05-09
    title: Products & Add-ons on appointments
    category: Workflow
    body: |
      Bike repair shops, salons, and other service businesses can now bill
      parts and supplies on a service appointment. Inventory decrements
      when the appointment is marked Completed; tax rolls up correctly.

  - date: 2026-05-09
    title: Custom items on appointments
    category: Workflow
    body: |
      Add ad-hoc parts to an appointment without inventory linkage, or
      rename any line item. Snapshot pricing preserved on completion.

  - date: 2026-05-09
    title: Theme contrast bump
    category: Polish
    body: |
      Theme B and Theme C tokens nudged for better readability without
      changing hue. Affects every dark-theme page.
YAML_FILE

cat > storage/changelog-import-samples/roadmap-2026-05-09.yml <<'YAML_FILE'
# Sample roadmap import file.
# Upload via Master Admin → Roadmap → Import from file.
#
# Required fields: status, title, body
# Optional fields: category, timeframe, order
# Valid statuses: shipped, in_progress, next_up, considering

entries:
  # Promotions to "shipped" from May 9 work
  - status: shipped
    title: Payment ledger architecture
    body: |
      Every dollar through the register, every dollar gets a ledger row.
      New tenant_appointment_payments table tracks deposits, balance
      payments, refunds, and overage refunds. Future-proofed for Stripe
      Connect partial payments via the tax_locked flag.

  - status: shipped
    title: Sale detail modal
    body: |
      Click any sale row in register history or customer activity to see
      details — line items, totals, payment method, refund button.

  - status: shipped
    title: Settings page restyle
    body: |
      Six-tab unified page (Business, Branding, Communication, Account,
      Appearance, Payments) replaces the old multi-page split.

  - status: shipped
    title: Products & Add-ons on appointments
    body: |
      Service appointments can now bill parts from inventory. Each line
      shows stock impact. Inventory commits on Completed status.

  # Next up
  - status: next_up
    title: Refund modal wiring
    timeframe: ~30 min
    order: 1
    body: |
      Wire the existing sale-detail modal's "Refund this sale" button to
      AppointmentPaymentService::refund(). Creates a refund ledger row
      tied to the original payment.

  - status: next_up
    title: Stripe Connect onboarding & deposits
    timeframe: multi-session
    order: 2
    body: |
      Connect account onboarding for tenants. Webhook for partial-payment
      deposits (will arrive with their own tax allocation — exactly the
      case tax_locked was designed for). Reconciliation. The actual
      last-step launch blocker.
YAML_FILE

echo "    wrote: storage/changelog-import-samples/changelog-2026-05-09.yml"
echo "    wrote: storage/changelog-import-samples/roadmap-2026-05-09.yml"

echo ""
echo "==> Patch complete."
echo ""
echo "Next steps on the server:"
echo "  php artisan optimize:clear"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo ""
echo "Then visit:"
echo "  Master Admin → Changelog → 'Import from file' button (top right)"
echo "  Master Admin → Roadmap   → 'Import from file' button (top right)"
echo ""
echo "Sample files are in storage/changelog-import-samples/ — download one,"
echo "upload it back through the UI to test the full preview-then-confirm flow."
