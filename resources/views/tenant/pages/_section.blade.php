@php
  $c    = $section->content ?? [];
  $type = $section->section_type;
  $typeLabels = [
    'nav'                    => 'Navigation bar',
    'hero'                   => 'Hero',
    'services'               => 'Services preview',
    'text_image'             => 'Text + image',
    'cta_banner'             => 'CTA banner',
    'image_gallery'          => 'Image gallery',
    'contact_form'           => 'Contact form',
    'booking_embed'          => 'Booking form embed',
    'footer'                 => 'Footer',
    'feature_grid'           => 'Feature grid',
    'step_timeline'          => 'Step timeline',
    'pricing_table'          => 'Pricing table',
    'faq_accordion'          => 'FAQ accordion',
    'testimonial_carousel'   => 'Testimonials',
    'logo_bar'               => 'Logo / trust bar',
    'comparison_table'       => 'Comparison table',
    'industry_pack_showcase' => 'Industry showcase',
    'stats_row'              => 'Stats row',
  ];
  $typeIcons = [
    'nav'=>'🧭','hero'=>'🖼','services'=>'⚙','text_image'=>'📝',
    'cta_banner'=>'📣','image_gallery'=>'🖼','contact_form'=>'✉',
    'booking_embed'=>'📅','footer'=>'⬇',
    'feature_grid'=>'▦','step_timeline'=>'🔢','pricing_table'=>'💲',
    'faq_accordion'=>'❓','testimonial_carousel'=>'💬','logo_bar'=>'⚑',
    'comparison_table'=>'📊','industry_pack_showcase'=>'🏷','stats_row'=>'📈',
  ];

  // Defensive decode: some older rows have JSON strings where arrays are
  // expected. Normalize everything up front so implode() etc. never trip.
  $arrayFields = ['features','steps','plans','items','testimonials','shop_names','logos','competitors','rows','stats','images'];
  foreach ($arrayFields as $af) {
      if (isset($c[$af]) && is_string($c[$af])) {
          $decoded = json_decode($c[$af], true);
          $c[$af] = is_array($decoded) ? $decoded : [];
      }
  }
@endphp
