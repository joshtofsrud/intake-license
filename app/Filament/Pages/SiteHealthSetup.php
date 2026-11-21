<?php

namespace App\Filament\Pages;

use App\Models\PlatformSettings;
use App\Models\Tenant\TenantGoogleConnection;
use App\Models\Tenant\TenantSiteHealth;
use App\Services\SiteHealth\GoogleSiteData;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-SITE-HEALTH: the two switches behind Site health's Google panels, and what
 * has to be true in Google Cloud before each one works for shops.
 */
class SiteHealthSetup extends Page
{
    use \App\Support\UsesAdminNav;
    protected static ?string $navigationIcon  = 'heroicon-o-heart';
    protected static ?string $navigationLabel = 'Site health';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?int    $navigationSort  = 60;
    protected static string  $view            = 'filament.pages.site-health-setup';
    protected static ?string $slug            = 'site-health';
    protected static ?string $title           = 'Site health';

    public bool $google = false;
    public bool $reviews = false;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'tenants');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $s = GoogleSiteData::settings();
        $this->google = $s['google'];
        $this->reviews = $s['reviews'];
    }

    public function configured(): bool { return GoogleSiteData::configured(); }
    public function callbackUrl(): string { return GoogleSiteData::callbackUrl(); }
    public function shopsChecked(): int { return TenantSiteHealth::whereNotNull('checked_at')->count(); }
    public function shopsConnected(): int { return TenantGoogleConnection::whereNotNull('refresh_token')->count(); }

    public function save(): void
    {
        $row = PlatformSettings::current();
        $row->site_health_settings = ['google' => $this->google, 'reviews' => $this->google && $this->reviews];
        $row->save();
        PlatformSettings::forget();
        $this->reviews = $this->google && $this->reviews;
        Notification::make()->title('Saved')->body($this->google ? 'Shops can connect Google' . ($this->reviews ? ', with reviews.' : '. Reviews stay off.') : 'Google is hidden from shops.')->success()->send();
    }
}
