<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public static function identities(): array
    {
        return [[['login' => '  ANGEL  ']], [['login' => 'angel@example.com']], [['email' => 'angel@example.com']]];
    }

    #[DataProvider('identities')]
    public function test_username_email_and_legacy_email_sign_in(array $identity): void
    {
        $user = User::factory()->create(['username' => 'angel', 'email' => 'angel@example.com', 'role' => 'admin']);
        $this->post(route('admin.login.submit'), $identity + ['password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    #[DataProvider('adminProfiles')]
    public function test_existing_accounts_without_a_username_still_sign_in_with_email(string $profile): void
    {
        $this->useAdminProfile($profile);
        $user = User::factory()->create(['role' => 'editor']);
        $this->post(route('admin.login.submit'), ['login' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_email_and_username_share_failed_attempts(): void
    {
        User::factory()->create(['username' => 'angel', 'email' => 'angel@example.com']);
        foreach (['angel', 'angel@example.com', 'ANGEL', 'angel@example.com', 'angel'] as $login) {
            $this->post(route('admin.login.submit'), ['login' => $login, 'password' => 'wrong'])
                ->assertSessionHasErrors('login');
        }

        $this->post(route('admin.login.submit'), ['login' => 'angel@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post(route('admin.login.submit'), ['login' => 'angel', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_username_never_bypasses_password_verification_and_invalid_payload_is_rejected(): void
    {
        User::factory()->create(['username' => 'angel']);
        $this->postJson(route('admin.login.submit'), ['login' => 'angel', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->postJson(route('admin.login.submit'), ['login' => ['angel'], 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->assertGuest();
    }

    public function test_profile_can_set_normalize_keep_but_cannot_clear_a_username(): void
    {
        $user = User::factory()->create(['role' => 'editor']);
        $data = ['name' => $user->name, 'email' => $user->email];
        $this->actingAs($user)->put(route('admin.profile.information.update'), $data + ['username' => '  ANGEL  '])
            ->assertRedirect();
        $this->assertSame('angel', $user->fresh()->username);
        $this->put(route('admin.profile.information.update'), $data)->assertRedirect();
        $this->assertSame('angel', $user->fresh()->username);
        $this->put(route('admin.profile.information.update'), $data + ['username' => ''])->assertSessionHasErrors('username');
        $this->assertSame('angel', $user->fresh()->username);
    }

    public function test_profile_and_user_admin_reject_case_insensitive_duplicate_and_email_shaped_usernames(): void
    {
        User::factory()->create(['username' => 'angel']);
        $user = User::factory()->create(['role' => 'admin']);
        $data = ['name' => $user->name, 'email' => $user->email, 'role' => 'admin'];
        foreach (['ANGEL', 'name@example.com', 'a', '0', 'ángel', str_repeat('a', 33)] as $username) {
            $this->actingAs($user)->putJson(route('admin.profile.information.update'), $data + ['username' => $username])
                ->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->putJson(route('admin.users.update', $user), $data + ['username' => $username])
                ->assertUnprocessable()->assertJsonValidationErrors('username');
        }
        $this->assertNull($user->fresh()->username);
    }

    public function test_creating_a_user_requires_a_nonempty_username(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['name' => 'New Editor', 'email' => 'new@example.test', 'role' => 'editor',
            'password' => 'password123', 'password_confirmation' => 'password123'];
        foreach ([[], ['username' => ''], ['username' => '  ']] as $username) {
            $this->actingAs($admin)->postJson(route('admin.users.store'), $data + $username)
                ->assertUnprocessable()->assertJsonValidationErrors('username');
        }
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    #[DataProvider('adminProfiles')]
    public function test_admin_can_create_update_and_find_users_by_username(string $profile): void
    {
        $this->useAdminProfile($profile);
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['name' => 'Editor', 'email' => 'editor@example.com', 'username' => 'First.Editor', 'role' => 'editor',
            'password' => 'password123', 'password_confirmation' => 'password123'];
        $this->actingAs($admin)->post(route('admin.users.store'), $data)->assertRedirect();
        $editor = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('first.editor', $editor->username);
        $data['username'] = 'NEW_EDITOR';
        $this->put(route('admin.users.update', $editor), $data)->assertRedirect();
        $this->assertSame('new_editor', $editor->fresh()->username);
        $this->get(route('admin.users.index', ['search' => 'new_editor']))
            ->assertOk()->assertSee('new_editor')->assertViewHas('users', fn ($users) => $users->count() === 1);
        $this->actingAs($editor)->post(route('admin.users.store'), $data)->assertForbidden();
    }
}
