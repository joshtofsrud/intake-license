<?php

namespace App\Filament\Pages;

use App\Models\DashboardBlock;
use App\Support\AdminAccess;
use App\Support\Dashboard\BlockAccess;
use App\Support\Dashboard\BlockRegistry;
use App\Support\Dashboard\BlockStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-DASH-BUILDER: every dashboard block — built-in and made here — where
 * each one is used, and Hide everywhere / Unhide. Made-here blocks open in
 * the block builder. Reached from the Blocks tab on Dashboards.
 */
class DashboardBlocks extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'dashboard-blocks';
    protected static ?string $title = 'Dashboards';
    protected static string $view = 'filament.pages.dashboard-blocks';

    public string $filter = 'all';
    public string $find = '';

    public function canManage(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    private function guard(): bool
    {
        if ($this->canManage()) return true;
        Notification::make()->title('View access only')->body('Only admins with full Tenants access can change blocks.')->danger()->send();

        return false;
    }

    private function run(callable $fn, string $ok, ?string $body = null): void
    {
        if (! $this->guard()) return;
        try {
            $b = $fn();
        } catch (\Throwable $e) {
            Notification::make()->title($e instanceof \InvalidArgumentException ? $e->getMessage() : 'That did not save. Try again.')->danger()->send();
            if (! $e instanceof \InvalidArgumentException) report($e);
            return;
        }
        Notification::make()->title(str_replace(':name', $b->name, $ok))->body($body)->success()->send();
    }

    public function hide(string $key): void
    {
        $this->run(fn () => BlockStore::setHidden($key, true), ':name is hidden everywhere', 'It has left every dashboard. Unhide puts it back where it was.');
    }

    public function unhide(string $key): void
    {
        $this->run(fn () => BlockStore::setHidden($key, false), ':name is back', 'It shows again wherever it was placed.');
    }

    public function duplicate(string $key)
    {
        if (! $this->guard()) return null;
        try {
            $b = BlockStore::duplicate($key);
        } catch (\Throwable $e) {
            Notification::make()->title($e instanceof \InvalidArgumentException ? $e->getMessage() : 'That did not save. Try again.')->danger()->send();
            return null;
        }

        return redirect(DashboardBlockBuilder::getUrl(['k' => $b->key]));
    }

    public function delete(string $key): void
    {
        $this->run(fn () => BlockStore::delete($key), ':name deleted', 'It has gone from every template it was on.');
    }

    protected function getViewData(): array
    {
        $rows = DashboardBlock::orderByDesc('is_builtin')->orderBy('id')->get();
        $defs = BlockRegistry::all();
        $usage = BlockStore::usage();
        $q = mb_strtolower(trim($this->find));

        $list = $rows->filter(fn ($b) => isset($defs[$b->key]))->map(function (DashboardBlock $b) use ($defs, $usage) {
            $d = $defs[$b->key];
            return [
                'key'     => $b->key,
                'name'    => $b->name,
                'builtin' => $b->is_builtin,
                'kind'    => $b->is_builtin ? 'data' : $b->kind,
                'desc'    => $d['desc'] ?? '',
                'reads'   => $d['reads'] ?? '',
                'who'     => BlockAccess::describe($b->key),
                'used'    => $usage[$b->key] ?? [],
                'hidden'  => $b->hidden_at !== null,
                'updated' => $b->updated_at,
            ];
        });

        $all = $list;
        $list = $list->filter(fn ($b) => match ($this->filter) {
            'builtin' => $b['builtin'], 'custom' => ! $b['builtin'], 'hidden' => $b['hidden'], default => true,
        })->filter(fn ($b) => $q === '' || str_contains(mb_strtolower($b['name']), $q))->values();

        return [
            'list'      => $list,
            'counts'    => ['builtin' => $all->where('builtin', true)->count(), 'custom' => $all->where('builtin', false)->count(), 'hidden' => $all->where('hidden', true)->count()],
            'canManage' => $this->canManage(),
        ];
    }
}
