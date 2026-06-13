<?php
// MARKER-PATCH-260

namespace App\Support;

/**
 * SiteTemplate — the single source of truth for the public-site design
 * presets. Mirrors the mockup's TEMPLATES + fakeSite() data. A preset is a
 * flat token bundle: the first five keys (accent/text/bg/font_heading/
 * font_body) map onto the discrete tenants.* design columns so the existing
 * public layout keeps working the instant a template is applied; the rest
 * are "extended" tokens that patch-262 wires into public/layout.blade.php.
 *
 * Keep this dependency-free and pure-data: it is read by the apply service,
 * the gallery view, and (later) the public layout, so it must never reach
 * into the DB or the container.
 */
class SiteTemplate
{
    /**
     * @return array<string,array<string,mixed>> keyed by template key.
     */
    public static function all(): array
    {
        return [
            'trail' => [
                'name'  => 'Trailhead',
                'desc'  => 'Bold, outdoorsy and high-contrast. Deep forest greens with a lime CTA — built for shops that lean adventure & MTB.',
                'tags'  => ['Dark hero', 'MTB', 'High contrast'],
                'tokens' => [
                    'accent'           => '#BEF264',
                    'text'             => '#0f1c12',
                    'bg'               => '#ffffff',
                    'font_heading'     => 'Inter',
                    'font_body'        => 'Inter',
                    'surface'          => '#f3f6ef',
                    'muted'            => '#5b6b5e',
                    'hero_bg'          => '#0f1c12',
                    'hero_text'        => '#f3f6ef',
                    'button_radius'    => 8,
                    'button_style'     => 'solid',
                    'heading_weight'   => 800,
                    'heading_transform'=> 'none',
                ],
            ],
            'velo' => [
                'name'  => 'Velo Studio',
                'desc'  => 'Calm, editorial and premium. Warm paper tones, light type, lots of whitespace — suits boutique road & fitting studios.',
                'tags'  => ['Light', 'Editorial', 'Boutique'],
                'tokens' => [
                    'accent'           => '#b5783f',
                    'text'             => '#1c1a17',
                    'bg'               => '#f7f3ec',
                    'font_heading'     => 'Playfair Display',
                    'font_body'        => 'Inter',
                    'surface'          => '#efe8dc',
                    'muted'            => '#6b6358',
                    'hero_bg'          => '#f7f3ec',
                    'hero_text'        => '#1c1a17',
                    'button_radius'    => 2,
                    'button_style'     => 'outline',
                    'heading_weight'   => 400,
                    'heading_transform'=> 'none',
                ],
            ],
            'spoke' => [
                'name'  => 'Spoke',
                'desc'  => 'Loud and athletic. Near-black canvas, heavy uppercase display, electric orange. Great for race shops and e-bike dealers.',
                'tags'  => ['Dark', 'Bold type', 'Sport'],
                'tokens' => [
                    'accent'           => '#FF5C38',
                    'text'             => '#f5f5f5',
                    'bg'               => '#0c0c0d',
                    'font_heading'     => 'Archivo',
                    'font_body'        => 'Inter',
                    'surface'          => '#16161a',
                    'muted'            => '#9a9aa2',
                    'hero_bg'          => '#0c0c0d',
                    'hero_text'        => '#f5f5f5',
                    'button_radius'    => 0,
                    'button_style'     => 'solid',
                    'heading_weight'   => 900,
                    'heading_transform'=> 'uppercase',
                ],
            ],
            'summit' => [
                'name'  => 'Summit',
                'desc'  => 'Clean, trustworthy and service-first. Soft neutrals, centered layouts, rounded buttons — leads with repairs & tune-ups.',
                'tags'  => ['Light', 'Service-led', 'Minimal'],
                'tokens' => [
                    'accent'           => '#3b7ea1',
                    'text'             => '#1f2a30',
                    'bg'               => '#ffffff',
                    'font_heading'     => 'Inter',
                    'font_body'        => 'Inter',
                    'surface'          => '#f1f5f7',
                    'muted'            => '#62737c',
                    'hero_bg'          => '#f1f5f7',
                    'hero_text'        => '#1f2a30',
                    'button_radius'    => 999,
                    'button_style'     => 'solid',
                    'heading_weight'   => 700,
                    'heading_transform'=> 'none',
                ],
            ],
            'cadence' => [
                'name'  => 'Cadence',
                'desc'  => 'Friendly and warm. Cream backgrounds, italic display, amber accents — a welcoming family-shop and rental feel.',
                'tags'  => ['Warm', 'Family', 'Approachable'],
                'tokens' => [
                    'accent'           => '#E8A33D',
                    'text'             => '#3a2f1c',
                    'bg'               => '#fbf6ec',
                    'font_heading'     => 'Fraunces',
                    'font_body'        => 'Inter',
                    'surface'          => '#f3ead7',
                    'muted'            => '#7a6b50',
                    'hero_bg'          => '#fbf6ec',
                    'hero_text'        => '#3a2f1c',
                    'button_radius'    => 12,
                    'button_style'     => 'solid',
                    'heading_weight'   => 700,
                    'heading_transform'=> 'none',
                ],
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<string,mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Resolved token bundle for a key, or null if unknown. */
    public static function tokens(string $key): ?array
    {
        $tpl = self::find($key);
        return $tpl['tokens'] ?? null;
    }

    public static function name(string $key): string
    {
        return self::find($key)['name'] ?? ucfirst($key);
    }
}
