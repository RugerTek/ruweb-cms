<?php

namespace Database\Seeders;

use App\Models\Locale;
use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class HomepageSeeder extends Seeder
{
    public function run(): void
    {
        // Every media slot this template exposes renders wide: the hero is full-bleed
        // and the social image is letterboxed by every platform, so both take the
        // horizontal placeholder.
        $placeholder = Media::where('placeholder_key', 'horizontal')->first();

        $page = Page::updateOrCreate(
            ['slug' => 'inicio', 'locale' => Locale::baseCode()],
            [
                'title' => 'Inicio',
                'template_key' => 'home',
                'content_json' => [
                    'meta' => [
                        'featured_image' => $placeholder?->id,
                    ],
                    'sections' => [
                        'hero' => [
                            'is_visible' => true,
                            'heading' => 'Bienvenido a Rüweb',
                            'body' => 'Este es un starter CMS rápido, flexible y extensible.',
                            'image_id' => $placeholder?->id,
                        ],
                        'features' => [
                            'is_visible' => true,
                            'heading' => 'Listo para editar',
                            'body' => 'Personalizá esta portada desde el panel de administración.',
                        ],
                        'cta' => [
                            'is_visible' => true,
                            'heading' => 'Empezá ahora',
                            'body' => 'Creá páginas, posts y ajustá la home en Settings.',
                        ],
                    ],
                ],
                'status' => 'published',
                'published_at' => now(),
                'created_by' => null,
                'updated_by' => null,
            ]
        );

        if ($placeholder && ! $page->mediaByCollection('featured_image')->whereKey($placeholder->id)->exists()) {
            $page->attachMedia($placeholder->id, 'featured_image');
        }

        $page->source_fingerprint = $page->translatableFingerprint();
        $page->save();

        Setting::set('homepage_translation_group_id', $page->translation_group_id);
    }
}
