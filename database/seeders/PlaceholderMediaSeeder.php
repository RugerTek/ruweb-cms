<?php

namespace Database\Seeders;

use App\Services\Media\PlaceholderMediaInstaller;
use Illuminate\Database\Seeder;

class PlaceholderMediaSeeder extends Seeder
{
    public function __construct(
        protected PlaceholderMediaInstaller $installer
    ) {
    }

    public function run(): void
    {
        foreach ($this->installer->install() as $key => $result) {
            $this->command?->getOutput()->writeln("  placeholder [{$key}]: {$result}");
        }
    }
}
