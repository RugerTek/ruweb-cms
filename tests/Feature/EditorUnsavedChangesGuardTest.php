<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\HomepageSeeder;
use Database\Seeders\LocaleSeeder;
use Database\Seeders\PlaceholderMediaSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorUnsavedChangesGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(LocaleSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(PlaceholderMediaSeeder::class);
        $this->seed(HomepageSeeder::class);
    }

    protected function editorResponse()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = Page::where('slug', 'inicio')->firstOrFail();

        return $this->actingAs($admin)->get(route('admin.pages.edit', $page));
    }

    public function test_editor_ships_the_unsaved_changes_copy_to_the_client(): void
    {
        $response = $this->editorResponse();

        $response->assertOk();
        $response->assertSee('unsavedChangesTitle', false);
        $response->assertSee('unsavedChangesMessage', false);
        $response->assertSee('btnLeaveWithoutSaving', false);
        $response->assertSee('btnKeepEditing', false);
        $response->assertSee(__('admin.unsaved_changes_message'), false);
    }

    public function test_editor_has_a_back_link_and_the_shared_modal_to_guard_it(): void
    {
        $response = $this->editorResponse();

        $response->assertOk();
        $response->assertSee(route('admin.pages.index'), false);
        $response->assertSee('id="confirm-modal"', false);
    }

    public function test_leave_warning_strings_exist_in_every_admin_locale(): void
    {
        $keys = [
            'admin.unsaved_changes_title',
            'admin.unsaved_changes_message',
            'admin.btn_leave_without_saving',
            'admin.btn_keep_editing',
        ];

        foreach (['es', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame(
                    $key,
                    __($key, [], $locale),
                    "Missing [{$key}] for locale [{$locale}]."
                );
            }
        }
    }
}
