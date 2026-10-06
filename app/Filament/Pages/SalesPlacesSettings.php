<?php
// MARKER-SALES-SETUP

namespace App\Filament\Pages;

use App\Models\SalesPlacesSearch;
use App\Models\SalesSetting;
use App\Services\Sales\PlacesClient;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** The Google Places key and monthly budget, moved out of Find shops into Sales setup. */
class SalesPlacesSettings extends Page
{
    use \App\Support\UsesAdminNav;
    protected static ?string $navigationIcon  = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'Google Places';
    protected static ?string $navigationGroup = 'Sales setup';
    protected static ?int    $navigationSort  = 4;
    protected static string  $view            = 'filament.pages.sales-places-settings';
    protected static ?string $slug            = 'sales-places';
    protected static ?string $title           = 'Google Places';

    public string $placesKey     = '';
    public int    $budgetDollars = 20;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->budgetDollars = (int) round(SalesSetting::placesBudgetCents() / 100);
    }

    public function configured(): bool { return (bool) SalesSetting::placesKey(); }
    public function monthToDateCents(): int { return SalesPlacesSearch::monthToDateCents(); }
    public function money(int $cents): string { return '$' . number_format($cents / 100, 2); }

    public function save(): void
    {
        $this->validate(['placesKey' => ['nullable', 'string', 'max:200'], 'budgetDollars' => ['required', 'integer', 'min:0', 'max:100000']]);
        $typed = trim($this->placesKey);
        if ($typed !== '') { SalesSetting::putPlacesKey($typed); $this->placesKey = ''; }
        SalesSetting::put('places_budget_cents', (string) ($this->budgetDollars * 100));
        Notification::make()->title('Saved')->body($typed !== '' ? 'Key stored, encrypted.' : 'Budget saved. Key left unchanged.')->success()->send();
    }

    public function testKey(): void
    {
        try {
            $loc = (new PlacesClient())->locate('Spokane, WA');
            Notification::make()->title($loc ? 'Places connected' : 'No result')
                ->body($loc ? 'Found ' . $loc['label'] . '.' : 'The key answered but returned nothing.')
                ->{$loc ? 'success' : 'warning'}()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Places failed')->body($e->getMessage())->danger()->send();
        }
    }
}
