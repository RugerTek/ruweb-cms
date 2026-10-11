<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleTranslationFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_translatable_fields_detects_the_changed_field(): void
    {
        $this->seedLocales();
        [$base, $translation] = $this->createSyncedPageTranslation();

        $base->update([
            'content_json' => [
                'sections' => [
                    'content' => [
                        'heading' => 'Servicios actualizados',
                        'body' => 'Cuerpo',
                    ],
                ],
            ],
        ]);
        $base->source_fingerprint = $base->translatableFingerprint();
        $base->source_field_hashes = $base->translatableFieldFingerprints();
        $base->save();

        $this->assertSame(['sections.content.heading'], $translation->fresh()->staleTranslatableFields());
    }

    public function test_saving_stale_translation_without_changes_fails_validation(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);
        [$base, $translation] = $this->createSyncedPageTranslation();
        $this->makeHeadingStale($base);

        $this->actingAs($user)
            ->from(route('admin.pages.edit', $translation))
            ->put(route('admin.pages.update', $translation), $this->pagePayload('Services'))
            ->assertRedirect(route('admin.pages.edit', $translation))
            ->assertSessionHasErrors('content_json.sections.content.heading');
    }

    public function test_saving_stale_translation_with_changed_field_passes(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);
        [$base, $translation] = $this->createSyncedPageTranslation();
        $this->makeHeadingStale($base);

        $this->actingAs($user)
            ->put(route('admin.pages.update', $translation), $this->pagePayload('Updated services'))
            ->assertRedirect();

        $this->assertSame([], $translation->fresh()->staleTranslatableFields());
    }

    public function test_saving_stale_translation_with_acknowledged_field_passes(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);
        [$base, $translation] = $this->createSyncedPageTranslation();
        $this->makeHeadingStale($base);

        $payload = $this->pagePayload('Services');
        $payload['acknowledged_fields'] = ['content_json[sections][content][heading]'];

        $this->actingAs($user)
            ->put(route('admin.pages.update', $translation), $payload)
            ->assertRedirect();

        $this->assertSame([], $translation->fresh()->staleTranslatableFields());
    }

    private function createSyncedPageTranslation(): array
    {
        $base = Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'content_json' => [
                'sections' => [
                    'content' => [
                        'heading' => 'Servicios',
                        'body' => 'Cuerpo',
                    ],
                ],
            ],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $base->source_fingerprint = $base->translatableFingerprint();
        $base->source_field_hashes = $base->translatableFieldFingerprints();
        $base->save();

        $translation = Page::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'Services',
            'slug' => 'services',
            'template_key' => 'default',
            'content_json' => [
                'sections' => [
                    'content' => [
                        'heading' => 'Services',
                        'body' => 'Body',
                    ],
                ],
            ],
            'status' => 'published',
            'published_at' => now(),
            'source_fingerprint' => $base->source_fingerprint,
            'source_field_hashes' => $base->source_field_hashes,
        ]);

        return [$base, $translation];
    }

    private function makeHeadingStale(Page $base): void
    {
        $base->update([
            'content_json' => [
                'sections' => [
                    'content' => [
                        'heading' => 'Servicios actualizados',
                        'body' => 'Cuerpo',
                    ],
                ],
            ],
        ]);
        $base->source_fingerprint = $base->translatableFingerprint();
        $base->source_field_hashes = $base->translatableFieldFingerprints();
        $base->save();
    }

    private function pagePayload(string $heading): array
    {
        return [
            'title' => 'Services',
            'locale' => 'en',
            'slug' => 'services',
            'template_key' => 'default',
            'status' => 'published',
            'content_json' => [
                'sections' => [
                    'content' => [
                        'heading' => $heading,
                        'body' => 'Body',
                    ],
                ],
            ],
        ];
    }

    private function seedLocales(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
    }
}
