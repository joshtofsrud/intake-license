<?php

namespace App\Support;

/**
 * Starting content for sections a SHOP adds. The builder's DEFAULTS were
 * written for intake.works (copy about Intake, links to its demo, a
 * comparison column called "Intake"), which is wrong on a shop's own site.
 * Shops get neutral example copy here instead; intake.works keeps DEFAULTS.
 * Example copy stays industry-neutral on purpose.
 */
class ShopSectionDefaults
{
    public static function content($tenant, string $type, array $default): array
    {
        if ($tenant && ($tenant->is_platform ?? false)) return $default;
        $shop = self::SHOP[$type] ?? null;
        return $shop === null ? $default : array_merge($default, $shop);
    }

    private const SHOP = [
        'features_accordion' => [
            'eyebrow' => 'What we do', 'heading' => 'Everything you need, in one place', 'accent_words' => '',
            'subheading' => 'Open a panel to see more.',
            'panels' => [
                ['title' => 'Services', 'heading' => 'Done right the first time', 'body' => 'Tell us what you need and we will take it from there.', 'visual' => 'none', 'color' => '#1f3b2b', 'accent' => '#BEF264', 'link_label' => 'Book now', 'link_url' => '/book', 'image' => ''],
                ['title' => 'Booking', 'heading' => 'Book online in minutes', 'body' => 'Pick a time that suits you. We confirm by email or text.', 'visual' => 'none', 'color' => '#172647', 'accent' => '#A9CBE8', 'link_label' => 'See times', 'link_url' => '/book', 'image' => ''],
                ['title' => 'About us', 'heading' => 'A local business that knows you', 'body' => 'Friendly people, honest prices and work we stand behind.', 'visual' => 'none', 'color' => '#3a2416', 'accent' => '#e39a5c', 'link_label' => '', 'link_url' => '', 'image' => ''],
            ],
            'start_panel' => 1,
        ],
        'feature_tiles' => [
            'heading' => 'What we offer',
            'tiles' => [
                ['icon' => 'wrench', 'title' => 'Services', 'body' => 'What we do best, explained simply.', 'chips' => '', 'wide' => false],
                ['icon' => 'calendar', 'title' => 'Online booking', 'body' => 'Choose a time that works for you.', 'chips' => '', 'wide' => false],
                ['icon' => 'gift', 'title' => 'Gift cards', 'body' => 'The easy present for someone you know.', 'chips' => '', 'wide' => false],
            ],
        ],
        'feature_groups' => [
            'heading' => 'Everything we do',
            'groups' => [
                ['label' => 'Services', 'heading' => 'What we take care of.', 'lead' => '', 'features' => [['title' => 'Our most popular service', 'body' => 'A short line about it.']]],
                ['label' => 'Visiting', 'heading' => 'Before you come in.', 'lead' => '', 'features' => [['title' => 'Booking', 'body' => 'Book online or give us a call.']]],
            ],
        ],
        'comparison_table' => [
            'heading' => 'Why choose us',
            'competitors' => ['Us', 'Others'],
            'rows' => [
                ['feature' => 'Book online any time', 'values' => ['yes', 'no']],
                ['feature' => 'Upfront pricing', 'values' => ['yes', 'no']],
            ],
        ],
        'testimonial_carousel' => [
            'heading' => 'What customers say',
            'testimonials' => [['quote' => 'Friendly, fast and great work. Highly recommend.', 'author' => 'Customer name', 'role' => '']],
        ],
    ];
}
