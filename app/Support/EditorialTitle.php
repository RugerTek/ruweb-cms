<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

final class EditorialTitle
{
    public static function render(?string $value): HtmlString
    {
        $escaped = e($value ?? '');
        $html = preg_replace('/\[highlight\](.*?)\[\/highlight\]/s', '<span class="editorial-highlight">$1</span>', $escaped);

        return new HtmlString($html ?? $escaped);
    }
}
