<?php

namespace Tests\Feature;

use App\Console\Commands\CmsInstallCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class CmsInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    private string $originalConfigPath;

    private string $originalStoragePath;

    private string $originalPublicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/cms-install-'.bin2hex(random_bytes(8));
        $this->originalConfigPath = $this->app->configPath();
        $this->originalStoragePath = $this->app->storagePath();
        $this->originalPublicPath = $this->app->publicPath();
        File::makeDirectory($this->fixture.'/config', 0755, true);
        File::makeDirectory($this->fixture.'/public/build', 0755, true);
        file_put_contents($this->fixture.'/config/cms.php', "<?php return ['admin_profile' => 'full'];\n");
        file_put_contents($this->fixture.'/public/build/manifest.json', '{}');
        $this->app->useConfigPath($this->fixture.'/config');
        $this->app->useStoragePath($this->fixture.'/storage');
        $this->app->usePublicPath($this->fixture.'/public');

    }

    protected function tearDown(): void
    {
        $this->app->useConfigPath($this->originalConfigPath);
        $this->app->useStoragePath($this->originalStoragePath);
        $this->app->usePublicPath($this->originalPublicPath);
        File::deleteDirectory($this->fixture);
        parent::tearDown();
    }

    private function installer(bool $successful = true): CommandTester
    {
        if ($successful) {
            Artisan::shouldReceive('call')->with('migrate', ['--force' => true])->once()->andReturn(0);
            Artisan::shouldReceive('call')->with('db:seed', \Mockery::type('array'))->times(3)->andReturn(0);
            Artisan::shouldReceive('call')->with('storage:link')->once()->andReturn(0);
            Artisan::shouldReceive('call')->with('cms:placeholders', [], \Mockery::any())->once()->andReturn(0);
            Artisan::shouldReceive('call')->with('config:clear')->once()->andReturn(0);
            Artisan::shouldReceive('call')->with('route:clear')->once()->andReturn(0);
        }
        $command = new class extends CmsInstallCommand
        {
            protected function updateEnv(array $values): void {}

            protected function configureDatabase(string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPass): bool
            {
                return true;
            }
        };
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }

    public static function profiles(): array
    {
        return [['full'], ['essential']];
    }

    #[DataProvider('profiles')]
    public function test_installer_asks_profile_and_required_username_and_account_can_sign_in(string $profile): void
    {
        User::factory()->create(['username' => 'taken']);
        $tester = $this->installer();
        $tester->setInputs([$profile, '127.0.0.1', '3306', 'fixture', 'root', '', 'es',
            'installed@example.test', 'Installed Admin', '', 'a', 'TAKEN', '  FIRST.ADMIN  ', 'install-password']);
        $this->assertSame(0, $tester->execute([]));
        $user = User::where('email', 'installed@example.test')->firstOrFail();
        $this->assertSame('first.admin', $user->username);
        $this->assertSame('admin', $user->role);
        $persisted = require $this->fixture.'/config/cms.php';
        $this->assertSame($profile, $persisted['admin_profile']);
        $this->assertSame($profile, config('cms.admin_profile'));
        $this->assertStringContainsString('Administration profile', $tester->getDisplay());
        $this->assertStringContainsString('required', $tester->getDisplay());
        $this->assertStringContainsString('taken', $tester->getDisplay());
        // Restore normal public/storage paths before exercising the real HTTP login flow.
        $this->app->usePublicPath($this->originalPublicPath);
        $this->app->useStoragePath($this->originalStoragePath);
        foreach (['FIRST.ADMIN', 'installed@example.test'] as $login) {
            $this->post(route('admin.login.submit'), ['login' => $login, 'password' => 'install-password'])
                ->assertRedirect(route('admin.dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post(route('admin.logout'));
        }
    }

    public function test_invalid_profile_fails_before_any_installation_changes(): void
    {
        $before = file_get_contents($this->fixture.'/config/cms.php');
        $tester = $this->installer(false);
        $this->assertSame(1, $tester->execute(['--profile' => 'unknown']));
        $this->assertStringContainsString('must be full or essential', $tester->getDisplay());
        $this->assertSame($before, file_get_contents($this->fixture.'/config/cms.php'));
        $this->assertSame(0, User::count());
    }

    public function test_reinstall_asks_for_a_missing_username_without_overwriting_credentials(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test', 'username' => null, 'role' => 'admin']);
        $before = $user->fresh()->only(['name', 'email', 'password', 'role', 'remember_token', 'security_version']);
        $tester = $this->installer();
        $tester->setInputs(['127.0.0.1', '3306', 'fixture', 'root', '', 'en', 'existing@example.test', '  EXISTING.ADMIN  ']);
        $this->assertSame(0, $tester->execute(['--profile' => 'essential']));
        $this->assertSame('existing.admin', $user->fresh()->username);
        $this->assertSame($before, $user->fresh()->only(array_keys($before)));
    }

    public function test_profile_option_skips_question_and_reinstall_preserves_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test', 'username' => 'existing', 'role' => 'admin']);
        $before = $user->fresh()->getAttributes();
        $tester = $this->installer();
        $tester->setInputs(['127.0.0.1', '3306', 'fixture', 'root', '', 'en', 'existing@example.test']);
        $this->assertSame(0, $tester->execute(['--profile' => 'essential']));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, User::count());
        $this->assertSame('essential', (require $this->fixture.'/config/cms.php')['admin_profile']);
        $this->assertStringNotContainsString('Admin username (required)', $tester->getDisplay());
    }
}
