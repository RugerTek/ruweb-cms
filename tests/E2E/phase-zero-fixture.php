<?php

$app = require __DIR__.'/phase-zero-bootstrap.php';
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\ContactPageSeeder;
use Database\Seeders\PlaceholderMediaSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$dbName = getenv('PHASE0_DATABASE');
// Create a fresh schema, never reuse or reset an existing database.
$config = config('database.connections.mysql');
$config['database'] = null;
config(['database.connections.phase0_create' => $config]);
DB::connection('phase0_create')->statement("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Artisan::call('migrate', ['--force' => true]);
foreach (['es', 'en'] as $locale) {
    Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true, 'is_public' => true]);
}
Artisan::call('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => PlaceholderMediaSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => ContactPageSeeder::class, '--force' => true]);
$admin = User::factory()->create(['name' => 'Phase Zero', 'email' => 'phase0@example.test', 'role' => 'admin', 'password' => Hash::make('Phase0-Test-Only-2026!')]);
$editor = User::factory()->create(['role' => 'editor']);
$keys = ['full' => McpApiKey::issue($admin, 'E2E admin', 'full', now()->addHour()), 'read' => McpApiKey::issue($admin, 'E2E read', 'read-only', now()->addHour()), 'draft' => McpApiKey::issue($admin, 'E2E draft', 'draft-only', now()->addHour()), 'editor' => McpApiKey::issue($editor, 'E2E editor', 'full', now()->addHour())];
$contact = Page::where('slug', 'contacto')->firstOrFail();
$content = $contact->content_json;
$content['sections']['form']['notify_enabled'] = true;
$content['sections']['form']['notify_email'] = 'inbox@example.test';
$contact->update(['content_json' => $content]);
Setting::set('mail_enabled', true);
Setting::set('recaptcha_site_key', 'isolated-test-site-key');
Setting::set('recaptcha_secret_key', 'isolated-test-secret');
$media = Media::where('placeholder_key', 'horizontal')->first() ?? Media::firstOrFail();
$base = Page::create(['title' => 'Baseline public', 'slug' => 'baseline-public', 'template_key' => 'default', 'status' => 'published', 'published_at' => now()->subDay(), 'content_json' => ['meta' => ['title' => 'Baseline SEO', 'description' => 'Baseline description'], 'sections' => ['content' => ['heading' => 'Baseline heading', 'body' => '<p>Baseline public body</p>', 'is_visible' => true]]]]);
$translated = Page::create(['title' => 'English baseline', 'slug' => 'baseline-public', 'locale' => 'en', 'translation_group_id' => $base->translation_group_id, 'template_key' => 'default', 'status' => 'draft']);
Menu::create(['name' => 'Phase zero menu', 'slug' => 'phase-zero', 'locale' => 'es']);
for ($i = 0; $i < 105; $i++) {
    Page::create(['title' => "Fixture $i", 'slug' => "fixture-$i", 'template_key' => 'default', 'status' => 'draft']);
    Post::create(['title' => "Fixture $i", 'slug' => "fixture-$i", 'status' => 'draft']);
    Media::create(['filename' => "$i.webp", 'original_filename' => "$i.webp", 'path' => "media/$i.webp", 'mime_type' => 'image/webp', 'extension' => 'webp', 'size' => 1, 'disk' => 'public']);
}
$manifest = ['database' => $dbName, 'keys' => $keys, 'public_page' => $base->id, 'translated_page' => $translated->id, 'contact_page' => $contact->id, 'media_id' => $media->id];
$path = getenv('PHASE0_STORAGE').'/manifest.json';
file_put_contents($path, json_encode($manifest));
chmod($path, 0600);
echo "Isolated MySQL fixture ready: $dbName\n";
