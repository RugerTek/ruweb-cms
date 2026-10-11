<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_admin_has_a_normalized_username_and_existing_credentials_are_preserved(): void
    {
        config(['cms.default_admin.username' => '  FIRST.ADMIN  ']);
        $this->seed(AdminUserSeeder::class);
        $user = User::where('email', config('cms.default_admin.email'))->firstOrFail();
        $this->assertSame('first.admin', $user->username);
        $before = $user->getAttributes();
        config(['cms.default_admin.username' => 'different', 'cms.default_admin.password' => 'different-password']);
        $this->seed(AdminUserSeeder::class);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_admin_seeder_rejects_a_missing_username(): void
    {
        config(['cms.default_admin.username' => '  ']);
        $this->expectException(ValidationException::class);
        $this->seed(AdminUserSeeder::class);
    }

    public function test_admin_seeder_rejects_a_duplicate_username(): void
    {
        User::factory()->create(['username' => 'taken']);
        config(['cms.default_admin.username' => 'TAKEN']);
        $this->expectException(ValidationException::class);
        $this->seed(AdminUserSeeder::class);
    }
}
