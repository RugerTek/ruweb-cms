<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use App\Services\Pages\PageOperations;
use Database\Seeders\PlaceholderMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A repeatable fixture of the real E2E02 agents' development task, outside starter content. */
class ProfileDevelopmentFixtureTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach (array_unique($this->createdFiles) as $path) {
            if (is_file(resource_path($path))) {
                unlink(resource_path($path));
            }
        }
        parent::tearDown();
    }

    public function test_the_same_custom_section_operates_in_each_profile_with_appropriate_editor_delivery(): void
    {
        foreach (['views/templates/profile-fixture.blade.php', 'views/admin/pages/sections/profile-fixture_testimonials.blade.php', 'views/admin/pages/sections/_profile-testimonial-item.blade.php'] as $path) {
            $this->assertFileDoesNotExist(resource_path($path));
        }
        foreach (['es', 'en'] as $locale) {
            Lang::addLines(['admin.section_testimonials' => 'Testimonios', 'admin.testimonial_item' => 'Testimonio :position', 'admin.testimonial_empty' => 'Sin testimonios', 'admin.testimonial_add' => 'Añadir testimonio', 'admin.testimonial_remove' => 'Eliminar testimonio', 'admin.testimonial_author' => 'Autor', 'admin.testimonial_role' => 'Cargo', 'admin.testimonial_quote' => 'Testimonio', 'admin.testimonial_portrait' => 'Retrato', 'admin.testimonial_confirm_remove' => 'Se eliminará este testimonio.'], $locale);
        }
        Storage::fake('public');
        $this->seed(PlaceholderMediaSeeder::class);
        $portrait = Media::where('placeholder_key', 'square')->firstOrFail();
        $actor = User::factory()->create(['role' => 'admin']);
        $fixture = base_path('tests/Fixtures/admin-profiles');
        config(['cms.templates' => ['profile-fixture' => require $fixture.'/template.php'], 'cms.forms' => []]);
        $section = ['heading' => 'Clientes que confían', 'is_visible' => true, 'items' => [['quote' => 'El equipo cumplió cada detalle.', 'author' => 'Ana Torres', 'role' => 'Directora', 'portrait_id' => $portrait->id]]];
        foreach (['full', 'essential'] as $profile) {
            config(['cms.admin_profile' => $profile]);
            copy($fixture.'/'.$profile.'/views/templates/profile-fixture.blade.php', resource_path('views/templates/profile-fixture.blade.php'));
            $this->createdFiles[] = 'views/templates/profile-fixture.blade.php';
            $partialPath = resource_path('views/admin/pages/sections/profile-fixture_testimonials.blade.php');
            if ($profile === 'full') {
                file_put_contents($partialPath, str_replace('admin.pages.sections._testimonial-item', 'admin.pages.sections._profile-testimonial-item', file_get_contents($fixture.'/full/views/admin/pages/sections/profile-fixture_testimonials.blade.php')));
                $this->createdFiles[] = 'views/admin/pages/sections/profile-fixture_testimonials.blade.php';
                $this->createdFiles[] = 'views/admin/pages/sections/_profile-testimonial-item.blade.php';
                copy($fixture.'/full/views/admin/pages/sections/_testimonial-item.blade.php', resource_path('views/admin/pages/sections/_profile-testimonial-item.blade.php'));
            } else {
                unlink($partialPath);
                // Essential delivers no new visual UI, including repeater helpers.
                unlink(resource_path('views/admin/pages/sections/_profile-testimonial-item.blade.php'));
            }
            View::getFinder()->flush();
            $this->assertSame(0, Artisan::call('cms:check-conventions'), Artisan::output());
            $page = app(PageOperations::class)->save($actor, ['title' => 'Profile '.$profile, 'slug' => 'fixture-'.$profile, 'template_key' => 'profile-fixture', 'content_json' => ['sections' => ['content' => ['heading' => 'Hermano conservado', 'body' => 'Contenido previo'], 'testimonials' => $section]]], draftOnly: true);
            $this->get('/fixture-'.$profile)->assertNotFound();
            $page = app(PageOperations::class)->save($actor, ['content_json' => ['sections' => ['testimonials' => ['heading' => 'Clientes que confían']]]], $page, draftOnly: true);
            $this->assertSame($section, $page->sections()['testimonials']);
            $this->assertSame('Contenido previo', $page->sections()['content']['body']);
            $page = app(PageOperations::class)->publish($actor, $page);
            $this->get('/fixture-'.$profile)->assertOk()->assertSee('El equipo cumplió cada detalle.')->assertSee('Ana Torres')->assertSee('Directora')->assertSee($portrait->url(), false);
            $this->actingAs($actor)->get('/admin/pages/'.$page->id.'/edit')->assertStatus($profile === 'full' ? 200 : 404);
            $page->update(['published_at' => now()->addDay()]);
            $this->get('/fixture-'.$profile)->assertNotFound();
            $before = $page->fresh()->getRawOriginal();
            config(['cms.admin_profile' => $profile === 'full' ? 'essential' : 'full']);
            $this->assertSame($before, $page->fresh()->getRawOriginal());
            // Essential delivery reports its missing editor before activation in full.
            if ($profile === 'essential') {
                $this->assertSame(1, Artisan::call('cms:check-conventions'));
                $this->assertStringContainsString('section-editor-missing', Artisan::output());
            }
            config(['cms.admin_profile' => $profile]);
            try {
                app(PageOperations::class)->save($actor, ['title' => 'Invalid', 'slug' => 'invalid-'.$profile, 'template_key' => 'profile-fixture', 'content_json' => ['sections' => ['testimonials' => ['items' => [['portrait_id' => 'https://example.test/image.png']]]]]], draftOnly: true);
                $this->fail('Media URL accepted in '.$profile);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content_json.sections.testimonials.items.0.portrait_id', $exception->errors());
            }
            $this->assertFalse(Page::where('slug', 'invalid-'.$profile)->exists());
        }
    }
}
