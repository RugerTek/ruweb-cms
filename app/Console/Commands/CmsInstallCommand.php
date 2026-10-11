<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AdminLoginPath;
use App\Support\Username;
use Database\Seeders\ContactPageSeeder;
use Database\Seeders\HomepageSeeder;
use Database\Seeders\LocaleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CmsInstallCommand extends Command
{
    protected $signature = 'cms:install {--profile= : Administration profile: full or essential}';

    protected $description = 'Install Rüweb CMS: configure database, run migrations, create admin user';

    public function handle(): int
    {
        $this->info('');
        $this->info('  ╔═══════════════════════════════╗');
        $this->info('  ║       Rüweb Installer         ║');
        $this->info('  ╚═══════════════════════════════╝');
        $this->info('');

        // Check PHP version
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $this->error('PHP 8.2 or higher is required. Current: '.PHP_VERSION);

            return Command::FAILURE;
        }
        $this->info('  ✓ PHP '.PHP_VERSION);

        // Check required extensions
        $required = ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'fileinfo'];
        $missing = [];
        foreach ($required as $ext) {
            if (! extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        if (! empty($missing)) {
            $this->error('Missing PHP extensions: '.implode(', ', $missing));

            return Command::FAILURE;
        }
        $this->info('  ✓ All required PHP extensions loaded');

        $profile = $this->option('profile') ?? $this->choice(
            'Administration profile', ['full', 'essential'], config('cms.admin_profile', 'full')
        );
        if (! in_array($profile, ['full', 'essential'], true)) {
            $this->error('Administration profile must be full or essential.');

            return Command::FAILURE;
        }

        // Database configuration
        $this->info('');
        $this->info('  Database Configuration');
        $this->info('  ─────────────────────');

        $dbHost = $this->ask('Database host', env('DB_HOST', '127.0.0.1'));
        $dbPort = $this->ask('Database port', env('DB_PORT', '3306'));
        $dbName = $this->ask('Database name', env('DB_DATABASE', 'ruweb-cms'));
        $dbUser = $this->ask('Database username', env('DB_USERNAME', 'root'));
        $dbPass = $this->secret('Database password (leave empty for none)') ?? '';
        $baseLocale = $this->choice('Base website language', ['es', 'en'], 'es');

        // Update .env
        $this->updateEnv([
            'APP_NAME' => 'Rüweb',
            'APP_URL' => 'http://localhost:8000',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $dbHost,
            'DB_PORT' => $dbPort,
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPass,
            'CMS_BASE_LOCALE' => $baseLocale,
        ]);

        if (! $this->configureDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPass)) {
            return Command::FAILURE;
        }

        // Run migrations
        $this->info('');
        $this->info('  Running migrations...');
        Artisan::call('migrate', ['--force' => true]);
        $this->info('  ✓ Migrations complete');

        // Seed default settings
        app(LocaleSeeder::class)->run($baseLocale);
        $this->info('  ✓ Locales seeded');

        Artisan::call('db:seed', [
            '--class' => SettingsSeeder::class,
            '--force' => true,
        ]);
        $this->info('  ✓ Default settings seeded');

        // Create admin user
        $this->info('');
        $this->info('  Admin User');
        $this->info('  ──────────');

        $adminEmail = $this->ask('Admin email', config('cms.default_admin.email'));
        $admin = User::where('email', $adminEmail)->first();
        if ($admin) {
            if (! $admin->username) {
                $admin->update(['username' => $this->askAdminUsername($admin->id)]);
            }
            $this->info('  ✓ Existing account preserved; manage it from the account panel');
        } else {
            $adminName = $this->ask('Admin name', config('cms.default_admin.name'));
            $adminUsername = $this->askAdminUsername();
            $adminPassword = $this->secret('Admin password') ?? config('cms.default_admin.password');
            $admin = User::create([
                'name' => $adminName,
                'username' => $adminUsername,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'role' => 'admin',
            ]);
            $this->info('  ✓ Admin user created');
        }

        // Storage link
        if (! file_exists(public_path('storage'))) {
            Artisan::call('storage:link');
            $this->info('  ✓ Storage linked');
        }

        // Create media directory
        $mediaPath = storage_path('app/public/media');
        if (! is_dir($mediaPath)) {
            mkdir($mediaPath, 0755, true);
        }
        $this->info('  ✓ Media directory ready');

        // Placeholder images (needs the storage link and media directory above)
        Artisan::call('cms:placeholders', [], $this->output);
        $this->info('  ✓ Placeholder images ready');

        Artisan::call('db:seed', [
            '--class' => HomepageSeeder::class,
            '--force' => true,
        ]);
        $this->info('  ✓ Homepage seeded');

        Artisan::call('db:seed', [
            '--class' => ContactPageSeeder::class,
            '--force' => true,
        ]);
        $this->info('  ✓ Contact page seeded');

        $this->persistProfile($profile);
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        $this->info('  ✓ Administration profile: '.$profile);
        $this->line('  Restart running workers/server processes after reinstalling.');

        $assetsBuilt = $this->frontendAssetsAreBuilt();
        if ($assetsBuilt) {
            $this->info('  ✓ Frontend assets built');
        }

        $this->info('');
        $this->info('  ════════════════════════════════');
        $this->info('  Rüweb CMS installed successfully!');
        $this->info('');
        $this->info('  Login at: '.config('app.url').AdminLoginPath::url());
        $this->info("  Email:    {$adminEmail}");
        $this->info("  Username: {$admin->username}");
        $this->info('  ════════════════════════════════');
        $this->info('');

        if (! $assetsBuilt) {
            $this->warn('  ⚠  Frontend assets are not built yet.');
            $this->warn('     Every page will fail until they are, because the layouts');
            $this->warn('     resolve their CSS and JS through the Vite manifest.');
            $this->line('');
            $this->line('     Build them where Node is available:');
            $this->line('');
            $this->line('       npm install');
            $this->line('       npm run build');
            $this->line('');
            $this->line('     On hosts without Node, build them elsewhere and upload public/build/.');
            $this->line('');
        }

        return Command::SUCCESS;
    }

    protected function configureDatabase(string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPass): bool
    {
        try {
            // Connect without database first to create it
            config([
                'database.connections.mysql.host' => $dbHost,
                'database.connections.mysql.port' => $dbPort,
                'database.connections.mysql.database' => null,
                'database.connections.mysql.username' => $dbUser,
                'database.connections.mysql.password' => $dbPass,
            ]);

            DB::purge('mysql');
            DB::connection('mysql')->getPdo();

            // Create database if it doesn't exist
            DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");

            // Now reconnect with the database selected
            config(['database.connections.mysql.database' => $dbName]);
            DB::purge('mysql');
            DB::connection('mysql')->getPdo();

            $this->info('  ✓ Database connected');
        } catch (\Exception $e) {
            $this->error('Could not connect to database: '.$e->getMessage());

            return false;
        }

        return true;
    }

    protected function askAdminUsername(?int $userId = null): string
    {
        do {
            $username = Username::normalize($this->ask('Admin username (required)'));
            $validator = Validator::make(['username' => $username], ['username' => Username::rules($userId)]);
            if ($validator->passes()) {
                return $username;
            }
            $this->error($validator->errors()->first('username'));
            if (! $this->input->isInteractive()) {
                throw new ValidationException($validator);
            }
        } while (true);
    }

    protected function persistProfile(string $profile): void
    {
        $path = config_path('cms.php');
        $content = file_get_contents($path);
        $updated = preg_replace("/('admin_profile'\\s*=>\\s*)'[^']*'/", '$1'."'{$profile}'", $content, 1, $count);
        if ($count !== 1 || file_put_contents($path, $updated) === false) {
            throw new \RuntimeException('Could not save admin_profile in config/cms.php.');
        }
        config(['cms.admin_profile' => $profile]);
    }

    /**
     * Vite writes this manifest on build; the layouts cannot resolve any asset without it.
     */
    public function frontendAssetsAreBuilt(): bool
    {
        return file_exists(public_path('build/manifest.json'))
            || file_exists(public_path('build/.vite/manifest.json'));
    }

    protected function updateEnv(array $values): void
    {
        $envPath = base_path('.env');
        $content = file_get_contents($envPath);

        foreach ($values as $key => $value) {
            $escaped = str_contains($value, ' ') ? "\"{$value}\"" : $value;

            if (preg_match("/^{$key}=.*/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$escaped}", $content);
            } else {
                $content .= "\n{$key}={$escaped}";
            }
        }

        file_put_contents($envPath, $content);
    }
}
