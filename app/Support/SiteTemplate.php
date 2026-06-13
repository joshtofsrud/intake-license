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
            'biscuit' => [
                'name'  => 'Biscuit',
                'desc'  => 'High-contrast and bold — a dark hero with one bright accent. Reads energetic and confident for any service business.',
                'tags'  => ['Dark hero', 'Bold', 'High contrast'],
                'layout' => [
                    ['type' => 'hero', 'variant' => 'fullbleed', 'h' => 'Great service, made simple.', 'sub' => 'Book your next visit in under two minutes.', 'cta' => 'Book now'],
                    ['type' => 'cta', 'h' => 'Ready when you are.', 'cta' => 'Get started'],
                    ['type' => 'footer'],
                ],
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
            'maple' => [
                'name'  => 'Maple',
                'desc'  => 'Calm and editorial. Warm paper tones, light type and generous whitespace — leans premium and boutique.',
                'tags'  => ['Light', 'Editorial', 'Boutique'],
                'layout' => [
                    ['type' => 'hero', 'variant' => 'split', 'h' => 'Considered, calm, and done right.', 'sub' => 'A studio that obsesses over the details.', 'cta' => 'Explore'],
                    ['type' => 'text_image', 'h' => 'Our approach', 'sub' => 'Thoughtful work, every time.'],
                    ['type' => 'gallery'],
                    ['type' => 'testimonial', 'sub' => '“Easily the best experience in town.”'],
                    ['type' => 'footer'],
                ],
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
            'pepper' => [
                'name'  => 'Pepper',
                'desc'  => 'Loud and athletic. Near-black canvas, heavy uppercase headings and an electric accent — high energy and modern.',
                'tags'  => ['Dark', 'Bold type', 'Athletic'],
                'layout' => [
                    ['type' => 'hero', 'variant' => 'centered', 'h' => 'Go further.', 'sub' => 'High-energy service, built for momentum.', 'cta' => 'Start now'],
                    ['type' => 'feature', 'h' => 'Everything you need'],
                    ['type' => 'services', 'h' => 'The lineup'],
                    ['type' => 'testimonial', 'sub' => '“Fast, friendly, and pro.”'],
                    ['type' => 'faq', 'h' => 'Good to know'],
                    ['type' => 'cta', 'h' => 'Let’s get going.', 'cta' => 'Book now'],
                    ['type' => 'footer'],
                ],
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
            'juniper' => [
                'name'  => 'Juniper',
                'desc'  => 'Clean and trustworthy. Soft neutrals, rounded buttons and centered layouts — service-first and approachable.',
                'tags'  => ['Light', 'Service-led', 'Minimal'],
                'layout' => [
                    ['type' => 'hero', 'variant' => 'compact', 'h' => 'Expert care, the easy way.', 'sub' => 'Trusted by the neighborhood.', 'cta' => 'See services'],
                    ['type' => 'services', 'h' => 'What we offer'],
                    ['type' => 'stats'],
                    ['type' => 'steps', 'h' => 'How it works'],
                    ['type' => 'cta', 'h' => 'Book your visit', 'cta' => 'Get started'],
                    ['type' => 'footer'],
                ],
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
            'cooper' => [
                'name'  => 'Cooper',
                'desc'  => 'Friendly and warm. Cream backgrounds and soft accents — a welcoming, community feel.',
                'tags'  => ['Warm', 'Welcoming', 'Approachable'],
                'layout' => [
                    ['type' => 'hero', 'variant' => 'split', 'h' => 'Bring the whole family.', 'sub' => 'A warm welcome, every visit.', 'cta' => 'Plan a visit'],
                    ['type' => 'text_image', 'h' => 'Made for everyone', 'sub' => 'Friendly faces and easy booking.'],
                    ['type' => 'feature', 'h' => 'Popular this season'],
                    ['type' => 'gallery'],
                    ['type' => 'contact', 'h' => 'Come say hi'],
                    ['type' => 'footer'],
                ],
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
