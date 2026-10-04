<?php

namespace App\Filament\Pages;

use App\Models\SiteSettings;
use App\Support\Brand as BrandAssets;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

/**
 * MARKER-BRAND — master admin › Brand: Intake's own logo, icon and share image.
 * Uploads wait until Save; the set they replace is kept (last five) so it can
 * be switched back. Shops' own logos are not touched.
 */
class Brand extends Page
{
    use \App\Support\UsesAdminNav;
    use WithFileUploads;

    protected static ?string $navigationIcon  = 'heroicon-o-swatch';
    protected static ?string $navigationLabel = 'Brand';
    protected static ?string $navigationGroup = 'Site & content';
    protected static ?int    $navigationSort  = 1;
    protected static string  $view            = 'filament.pages.brand';
    protected static ?string $slug            = 'brand';

    /** slot => [label, help, accepted mimes] */
    public const SLOTS = [
        'logo'       => ['Logo for dark backgrounds', 'Light wordmark. The marketing site and app screens.', 'svg,png'],
        'logo_light' => ['Logo for light backgrounds', 'Dark wordmark. Printed pages and light screens.', 'svg,png'],
        'email'      => ['Logo for email', 'A PNG of the light-background logo, about 600 px wide — many inboxes don\'t show SVG.', 'png'],
        'icon'       => ['Icon', 'The square mark on its own. Also used as the browser-tab icon.', 'svg,png'],
        'icon_png'   => ['Icon, 512 × 512 PNG', 'The 16, 32 and home-screen sizes are made from this.', 'png'],
        'og'         => ['Share image', 'What a shared link to intake.works shows — 1200 × 630. Pages can set their own.', 'png,jpg,jpeg'],
    ];

    public array $up = [];

    public function save(): void
    {
        $rules = [];
        foreach (self::SLOTS as $slot => $meta) {
            $rules["up.$slot"] = ['nullable', 'file', 'mimes:' . $meta[2], 'max:4096'];
        }
        $this->validate($rules);

        $settings = SiteSettings::current();
        $before   = (array) ($settings->brand ?? []);
        $brand    = $before;
        $changed  = [];

        foreach (self::SLOTS as $slot => $meta) {
            $file = $this->up[$slot] ?? null;
            if (! $file) {
                continue;
            }
            $ext  = strtolower($file->getClientOriginalExtension() ?: 'png');
            $path = $file->storeAs('brand', $slot . '-' . now()->format('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext, 'public');
            $brand[$slot] = $path;
            $changed[] = $meta[0];

            if ($slot === 'icon_png') {
                foreach (['favicon_16' => 16, 'favicon_32' => 32, 'apple' => 180] as $k => $px) {
                    if ($made = $this->resized($path, $px)) {
                        $brand[$k] = $made;
                    }
                }
            }
        }

        if (! $changed) {
            Notification::make()->title('Nothing to save — choose a file first.')->warning()->send();
            return;
        }

        $history = (array) ($settings->brand_history ?? []);
        if ($before) {
            array_unshift($history, ['brand' => $before, 'saved_at' => now()->toIso8601String(),
                                     'by' => auth()->user()?->name]);
        }
        $settings->brand = $brand;
        $settings->brand_history = array_slice($history, 0, 5);
        $settings->save();
        BrandAssets::flush();
        $this->up = [];

        Notification::make()->title('Saved — ' . implode(', ', $changed) . '. Live everywhere now.')->success()->send();
    }

    public function restore(int $i): void
    {
        $settings = SiteSettings::current();
        $history  = (array) ($settings->brand_history ?? []);
        if (! isset($history[$i])) {
            return;
        }
        $current = (array) ($settings->brand ?? []);
        $restore = (array) ($history[$i]['brand'] ?? []);
        unset($history[$i]);
        array_unshift($history, ['brand' => $current, 'saved_at' => now()->toIso8601String(), 'by' => auth()->user()?->name]);
        $settings->brand = $restore;
        $settings->brand_history = array_slice(array_values($history), 0, 5);
        $settings->save();
        BrandAssets::flush();
        Notification::make()->title('Switched back. Live everywhere now.')->success()->send();
    }

    public function discard(): void
    {
        $this->up = [];
    }

    /** Make a square PNG of $px from the stored 512 icon. GD only; null if unavailable. */
    private function resized(string $path, int $px): ?string
    {
        if (! function_exists('imagecreatefrompng')) {
            return null;
        }
        $src = @imagecreatefrompng(Storage::disk('public')->path($path));
        if (! $src) {
            return null;
        }
        $dst = imagecreatetruecolor($px, $px);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $px, $px, imagesx($src), imagesy($src));
        $out = 'brand/icon-' . $px . '-' . now()->format('YmdHis') . '.png';
        ob_start();
        imagepng($dst);
        Storage::disk('public')->put($out, ob_get_clean());
        imagedestroy($src);
        imagedestroy($dst);
        return $out;
    }

    protected function getViewData(): array
    {
        $settings = SiteSettings::current();
        return [
            'slots'   => self::SLOTS,
            'urls'    => collect(array_keys(self::SLOTS))->mapWithKeys(fn ($s) => [$s => $s === 'icon_png'
                            ? BrandAssets::url('apple') : BrandAssets::url($s)])->all(),
            'history' => array_values((array) ($settings->brand_history ?? [])),
            'gd'      => function_exists('imagecreatefrompng'),
        ];
    }
}
