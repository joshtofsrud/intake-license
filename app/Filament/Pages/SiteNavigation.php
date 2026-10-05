<?php

namespace App\Filament\Pages;

use App\Models\Tenant\TenantNavItem;
use App\Support\MarketingNav;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-MKT-NAV — the one place that controls the intake.works header.
 * Replaces the old Navigation resource (typed label + URL rows), the page
 * editor's "Show in site navigation" checkbox and the Marketing pages
 * "Show in navigation" toggle, none of which agreed with each other.
 */
class SiteNavigation extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-bars-3';
    protected static ?string $navigationLabel = 'Navigation';
    protected static ?string $navigationGroup = 'Site & content';
    protected static ?int    $navigationSort  = 20;
    protected static string  $view            = 'filament.pages.site-navigation';
    protected static ?string $slug            = 'navigation';
    protected static ?string $title           = 'Navigation';

    public const MAX_ROWS = 30;

    protected function getViewData(): array
    {
        return [
            'rows'       => MarketingNav::rows(),
            'pages'      => MarketingNav::pages(),
            'header'     => MarketingNav::header(), // MARKER-MKT-NAV-FLOAT
            'footer'     => MarketingNav::footer(),  // MARKER-MKT-FOOTER
            'previewUrl' => url('/admin/navigation/preview'),
        ];
    }

    /** Called from the page with the whole list; replaces the menu in one go. */
    public function save(array $rows, array $header = [], array $footer = []): void
    {
        $platform = MarketingNav::platform();
        if (! $platform) {
            Notification::make()->danger()->title('No intake.works account found.')->send();
            return;
        }

        $pages = MarketingNav::pages();
        $clean = [];
        $problems = [];

        foreach (array_slice(array_values($rows), 0, self::MAX_ROWS) as $n => $r) {
            $pos   = $n + 1;
            $type  = ($r['type'] ?? '') === 'page' ? 'page' : 'link';
            $label = mb_substr(trim((string) ($r['label'] ?? '')), 0, 40);
            $style = in_array($r['style'] ?? '', MarketingNav::STYLES, true) ? $r['style'] : 'link';
            $side  = in_array($r['side'] ?? '', MarketingNav::SIDES, true) ? $r['side'] : 'left';
            $tab   = ! empty($r['tab']);

            if ($type === 'page') {
                $pid = (string) ($r['page'] ?? '');
                if (! isset($pages[$pid])) { $problems[] = "Row $pos points at a page that no longer exists."; continue; }
                $url = $pages[$pid]['path'];
            } else {
                $pid = null;
                $url = mb_substr(trim((string) ($r['url'] ?? '')), 0, 255);
                if ($label === '')                                          { $problems[] = "Row $pos needs a label."; continue; }
                if (! preg_match('#^(/|\#|https?://|mailto:|tel:)#i', $url))  { $problems[] = "Row $pos needs an address starting with /, #, https://, mailto: or tel:."; continue; }
            }

            $host = parse_url($url, PHP_URL_HOST);
            $clean[] = [
                'page_id' => $pid, 'label' => $type === 'page' && $label === '' ? '' : $label, 'url' => $url,
                'style' => $style, 'side' => $side, 'open_in_new_tab' => $tab,
                'is_external' => $host && ! str_ends_with($host, (string) config('intake.domain', 'intake.works')),
            ];
        }

        if ($problems) {
            Notification::make()->danger()->title('Not saved')->body(implode(' ', $problems))->send();
            return;
        }

        DB::transaction(function () use ($platform, $clean, $header, $footer) {
            // MARKER-MKT-FOOTER — the footer saves with the menu.
            if ($footer) {
                $settings = $platform->settings ?? [];
                $settings['marketing_footer'] = MarketingNav::cleanFooter($footer);
                $platform->settings = $settings;
                $platform->save();
            }
            // MARKER-MKT-NAV-FLOAT — header style and its settings save with the menu.
            if ($header) {
                $settings = $platform->settings ?? [];
                $settings['marketing_header'] = MarketingNav::cleanHeader($header);
                $platform->settings = $settings;
                $platform->save();
            }
            TenantNavItem::where('tenant_id', $platform->id)->delete();
            foreach ($clean as $i => $row) {
                $item = new TenantNavItem();
                $item->forceFill($row + ['id' => (string) Str::uuid(), 'tenant_id' => $platform->id, 'sort_order' => $i]);
                $item->save();
            }
        });

        Notification::make()->success()->title('Menu saved — live on every marketing page.')->send();
        $this->dispatch('nav-saved');
    }
}
