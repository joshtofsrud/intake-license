<?php

namespace App\Filament\Pages;

use App\Support\AdminAccess;
use App\Support\BrandNames as Names;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-BRAND-NAMES: one brand, one spelling across distributors.
 * Lists likely duplicates (1st/First, &/and, case, spacing, Inc/Co), lets
 * master admin choose the spelling, and keeps every pairing undoable.
 */
class BrandNames extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Brand names';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 62;
    protected static ?string $title           = 'Brand names';
    protected static ?string $slug            = 'brand-names';
    protected static string  $view            = 'filament.pages.brand-names';

    public string $tab = 'suggested';
    public string $find = '';
    public string $from = '';
    public string $to = '';

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'catalog') === 'full';
    }

    public function setTab(string $t): void { if (in_array($t, ['suggested', 'pairings', 'add'], true)) $this->tab = $t; }

    /** Every brand spelling in the shared catalog, with items per distributor. */
    private function spellings(): array
    {
        $rows = DB::table('platform_distributor_catalogs')
            ->where('is_active', true)->whereNotNull('manufacturer')->whereRaw("TRIM(manufacturer) <> ''")
            ->selectRaw('manufacturer AS m, distributor_code AS d, COUNT(*) AS n')
            ->groupBy('manufacturer', 'distributor_code')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->m]['name'] = $r->m;
            $out[$r->m]['by'][$r->d] = (int) $r->n;
            $out[$r->m]['total'] = ($out[$r->m]['total'] ?? 0) + (int) $r->n;
        }
        return $out;
    }

    /** Groups of two or more spellings that fold to the same name. */
    public function suggestions(): array
    {
        $groups = [];
        foreach ($this->spellings() as $name => $s) {
            $f = Names::fold($name);
            if ($f === '') continue;
            $groups[$f][] = $s;
        }
        $groups = array_filter($groups, fn ($g) => count($g) > 1);
        $term = mb_strtolower(trim($this->find));
        if ($term !== '') {
            $groups = array_filter($groups, fn ($g) => collect($g)->contains(fn ($s) => str_contains(mb_strtolower($s['name']), $term)));
        }
        foreach ($groups as &$g) {
            usort($g, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));
        }
        unset($g);
        uasort($groups, fn ($a, $b) => strcasecmp($a[0]['name'], $b[0]['name']));
        return $groups;
    }

    /** Use $winner for every spelling in the suggested group $fold. */
    public function useSpelling(string $fold, string $winner): void
    {
        abort_unless($this->canEdit(), 403);
        $group = $this->suggestions()[$fold] ?? null;
        if (! $group || ! collect($group)->pluck('name')->contains($winner)) {
            Notification::make()->title('That group has changed — reload and try again.')->warning()->send();
            return;
        }
        $by = Auth::guard('web')->user()?->email;
        $n = 0;
        foreach ($group as $s) {
            if ($s['name'] !== $winner) $n += Names::pair($s['name'], $winner, $by);
        }
        Notification::make()->title('Now one brand: ' . $winner)->body(number_format($n) . ' catalog items relabelled.')->success()->send();
    }

    public function addPairing(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['from' => ['required', 'string', 'max:191'], 'to' => ['required', 'string', 'max:191', 'different:from']]);
        $n = Names::pair($this->from, $this->to, Auth::guard('web')->user()?->email);
        Notification::make()->title('"' . $this->from . '" now shows as "' . $this->to . '"')->body(number_format($n) . ' catalog items relabelled.')->success()->send();
        $this->from = $this->to = '';
        $this->tab = 'pairings';
    }

    public function undo(int $id): void
    {
        abort_unless($this->canEdit(), 403);
        $n = Names::unpair($id);
        Notification::make()->title('Pairing removed')->body(number_format($n) . ' catalog items back to the distributor\'s own spelling.')->success()->send();
    }

    public function pairings(): array
    {
        $counts = DB::table('platform_distributor_catalogs')->whereNotNull('manufacturer_raw')
            ->selectRaw('LOWER(TRIM(manufacturer_raw)) AS k, COUNT(*) AS n')->groupBy('k')->pluck('n', 'k')->all();
        return DB::table('brand_aliases')->orderBy('canonical_name')->orderBy('alias_name')->get()
            ->map(function ($a) use ($counts) { $a->items = (int) ($counts[$a->alias_key] ?? 0); return $a; })->all();
    }

    /** Spellings for the add form's suggestions list. */
    public function allNames(): array
    {
        return collect($this->spellings())->keys()->sort(fn ($a, $b) => strcasecmp($a, $b))->values()->all();
    }
}
