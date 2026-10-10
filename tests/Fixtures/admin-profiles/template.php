<?php

// Deterministic equivalent of the same brief completed by the two real E2E agents.
return [
    'name' => 'Profile fixture',
    'sections' => ['content', 'testimonials'],
    'schema' => [
        'sections.content.heading' => ['type' => 'text', 'translatable' => true],
        'sections.content.body' => ['type' => 'textarea', 'translatable' => true],
        'sections.content.is_visible' => ['type' => 'boolean', 'preserve' => true],
        'sections.testimonials.heading' => ['type' => 'text', 'translatable' => true],
        'sections.testimonials.is_visible' => ['type' => 'boolean', 'preserve' => true],
        'sections.testimonials.items.*.quote' => ['type' => 'textarea', 'translatable' => true],
        'sections.testimonials.items.*.author' => ['type' => 'text', 'preserve' => true],
        'sections.testimonials.items.*.role' => ['type' => 'text', 'translatable' => true],
        'sections.testimonials.items.*.portrait_id' => ['type' => 'media', 'localized_media' => true],
    ],
];
