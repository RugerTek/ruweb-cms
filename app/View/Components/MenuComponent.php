<?php

namespace App\View\Components;

use App\Models\Menu;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class MenuComponent extends Component
{
    public Collection $items;

    public function __construct(string $slug = 'header', ?string $location = null)
    {
        $menu = $location
            ? Menu::findByLocation($location)
            : Menu::findBySlug($slug);

        $this->items = $menu ? $menu->tree(public: true) : collect();
    }

    public function render()
    {
        return view('components.menu');
    }
}
