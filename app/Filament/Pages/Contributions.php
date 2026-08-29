<?php

namespace App\Filament\Pages;

// MARKER-CONTRIBUTIONS
use App\Models\Contribution;
use Filament\Pages\Page;

class Contributions extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-hand-raised';
    protected static ?string $navigationGroup = 'Raise';
    protected static ?string $navigationLabel = 'Contributions';
    protected static ?int    $navigationSort  = 30;
    protected static string  $view = 'filament.pages.contributions';

    protected function getViewData(): array
    {
        $all = Contribution::orderByDesc('created_at')->limit(200)->get();

        return [
            'contributions' => $all,
            'paidTotal'     => Contribution::totalPaidCents(),
            'paidCount'     => $all->where('status', 'paid')->count(),
            'pendingCount'  => $all->where('status', 'pending')->count(),
        ];
    }
}
