<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminProfiles')]
    public function test_admin_can_access_analytics_page(string $profile): void
    {
        $this->useAdminProfile($profile);
        $this->seed(SettingsSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.analytics.index'));

        $response->assertOk();
        $response->assertSee('Google tag');
        $response->assertSee('Meta Pixel');
        $response->assertSee('Search Console');
    }

    #[DataProvider('adminProfiles')]
    public function test_editor_cannot_access_analytics_page(string $profile): void
    {
        $this->useAdminProfile($profile);
        $this->seed(SettingsSeeder::class);
        $editor = User::factory()->create(['role' => 'editor']);

        $response = $this->actingAs($editor)->get(route('admin.analytics.index'));

        $response->assertForbidden();
    }

    #[DataProvider('adminProfiles')]
    public function test_admin_can_save_valid_analytics_values(string $profile): void
    {
        $this->useAdminProfile($profile);
        $this->seed(SettingsSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->put(route('admin.analytics.update'), [
            'google_tag_id' => 'G-TEST123456',
            'meta_pixel_id' => '123456789012345',
            'search_console_verification_token' => 'abc123_DEF-456',
        ]);

        $response->assertRedirect(route('admin.analytics.index'));
        $this->assertSame('G-TEST123456', Setting::get('google_tag_id'));
        $this->assertSame('123456789012345', Setting::get('meta_pixel_id'));
        $this->assertSame('abc123_DEF-456', Setting::get('search_console_verification_token'));
    }

    public function test_invalid_google_tag_input_is_rejected(): void
    {
        $this->seed(SettingsSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->from(route('admin.analytics.index'))
            ->actingAs($admin)
            ->put(route('admin.analytics.update'), [
                'google_tag_id' => '<script>alert(1)</script>',
            ]);

        $response->assertRedirect(route('admin.analytics.index'));
        $response->assertSessionHasErrors('google_tag_id');
    }

    public function test_invalid_meta_pixel_input_is_rejected(): void
    {
        $this->seed(SettingsSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->from(route('admin.analytics.index'))
            ->actingAs($admin)
            ->put(route('admin.analytics.update'), [
                'meta_pixel_id' => 'fbq("init")',
            ]);

        $response->assertRedirect(route('admin.analytics.index'));
        $response->assertSessionHasErrors('meta_pixel_id');
    }

    public function test_full_search_console_html_tag_is_rejected(): void
    {
        $this->seed(SettingsSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->from(route('admin.analytics.index'))
            ->actingAs($admin)
            ->put(route('admin.analytics.update'), [
                'search_console_verification_token' => '<meta name="google-site-verification" content="token" />',
            ]);

        $response->assertRedirect(route('admin.analytics.index'));
        $response->assertSessionHasErrors('search_console_verification_token');
    }
}
