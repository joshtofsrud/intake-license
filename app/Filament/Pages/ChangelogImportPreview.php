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
            ? route('filament.admin.resources.roadmap.index')
            : route('filament.admin.resources.changelog.index');
    }
}
