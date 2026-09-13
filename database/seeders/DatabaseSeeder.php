<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LocaleSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,
            // Placeholders first: starter content references them by placeholder key.
            PlaceholderMediaSeeder::class,
            HomepageSeeder::class,
            ContactPageSeeder::class,
            MenuSeeder::class,
        ]);
    }
}
