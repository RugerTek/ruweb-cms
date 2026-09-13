<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Locale;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $header = Menu::updateOrCreate(
            ['slug' => 'header', 'locale' => Locale::baseCode()],
            ['name' => 'Header Menu', 'location' => 'header']
        );

        if ($header->items()->count() === 0) {
            MenuItem::create([
                'menu_id' => $header->id,
                'label'   => 'Home',
                'type'    => 'custom_link',
                'url'     => '/',
                'target'  => '_self',
                'order'   => 0,
            ]);

            MenuItem::create([
                'menu_id' => $header->id,
                'label'   => 'Blog',
                'type'    => 'custom_link',
                'url'     => '/blog',
                'target'  => '_self',
                'order'   => 1,
            ]);

            MenuItem::create([
                'menu_id' => $header->id,
                'label'   => 'About',
                'type'    => 'custom_link',
                'url'     => '/about',
                'target'  => '_self',
                'order'   => 2,
            ]);

            MenuItem::create([
                'menu_id' => $header->id,
                'label'   => 'Contacto',
                'type'    => 'custom_link',
                'url'     => '/contacto',
                'target'  => '_self',
                'order'   => 3,
            ]);
        }

        Menu::updateOrCreate(
            ['slug' => 'footer', 'locale' => Locale::baseCode()],
            ['name' => 'Footer Menu', 'location' => 'footer']
        );
    }
}
