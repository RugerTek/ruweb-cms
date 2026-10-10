<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\Content\ContentSchemaRegistry;
use App\Support\CompositionHtmlSanitizer;
use App\Support\PageComposition;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MediaUsage
{
    /** Includes unpublished and localized content: neither is safe to break. */
    public function references(Media $media): array
    {
        $references = [];
        foreach (DB::table('mediables')->where('media_id', $media->id)->get() as $pivot) {
            $references[] = ['type' => 'content', 'id' => $pivot->mediable_id, 'label' => class_basename($pivot->mediable_type).' #'.$pivot->mediable_id, 'field' => $pivot->collection];
        }
        foreach (Setting::with('translations')->where('type', 'media')->get() as $setting) {
            if ((int) $setting->value === $media->id) {
                $references[] = ['type' => 'setting', 'id' => $setting->id, 'label' => $setting->key, 'field' => 'value'];
            }
            foreach ($setting->translations as $translation) {
                if ((int) $translation->value === $media->id) {
                    $references[] = ['type' => 'setting', 'id' => $setting->id, 'label' => $setting->key.' ('.$translation->locale.')', 'field' => 'value'];
                }
            }
        }
        $registry = app(ContentSchemaRegistry::class);
        foreach ([Page::class, Post::class] as $model) {
            foreach ($model::query()->cursor() as $content) {
                $data = $registry->copyForTranslation($content);
                if ($content instanceof Page && in_array((int) $media->id, PageComposition::mediaIds($content->content_json ?? []), true)) {
                    $references[] = ['type' => 'pages', 'id' => $content->id, 'label' => $content->title.' ('.$content->locale.')', 'field' => 'composition'];
                }
                foreach ($registry->forEntity($content) ?? [] as $path => $schema) {
                    if (($schema['type'] ?? null) !== 'media') {
                        continue;
                    }
                    $values = Arr::flatten((array) data_get($data, $path, []));
                    if (in_array((string) $media->id, array_map('strval', $values), true)) {
                        $references[] = ['type' => $content->getTable(), 'id' => $content->id, 'label' => $content->title.' ('.$content->locale.')', 'field' => $path];
                    }
                }
                // Rich text supports media URLs; retain those assets as well as id-based slots.
                $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $path = parse_url($media->url(), PHP_URL_PATH);
                if ($path && str_contains($encoded, $path)) {
                    $references[] = ['type' => $content->getTable(), 'id' => $content->id, 'label' => $content->title.' ('.$content->locale.')', 'field' => 'content'];
                }
            }
        }

        if (Schema::hasTable('editorial_versions')) {
            foreach (DB::table('editorial_versions')->orderBy('id')->cursor() as $version) {
                $payload = json_decode($version->payload, true) ?? [];
                $ids = array_column($payload['media'] ?? [], 'id');
                if (in_array($media->id, $ids) || $this->containsMedia($payload['attributes'] ?? [], $media)) {
                    $references[] = ['type' => 'version', 'id' => $version->id, 'label' => __('media_safety.version', ['id' => $version->id]), 'field' => $version->content_type.' #'.$version->content_id];
                }
            }
        }
        if (Schema::hasTable('editorial_drafts')) {
            foreach (DB::table('editorial_drafts')->where('expires_at', '>', now())->cursor() as $draft) {
                foreach (json_decode($draft->payload, true) ?? [] as $entry) {
                    if (is_array($entry) && count($entry) === 2 && is_string($entry[1])
                        && in_array((int) $media->id, app(CompositionHtmlSanitizer::class)->mediaIds($entry[1]), true)) {
                        $references[] = ['type' => 'draft', 'id' => $draft->id, 'label' => __('media_safety.draft'), 'field' => $draft->content_type.' #'.$draft->content_id];
                        break;
                    }
                    if (is_array($entry) && count($entry) === 2
                        && preg_match('/(?:image_id|media_id|featured_image)(?:\]|$)/', (string) $entry[0])
                        && (string) $entry[1] === (string) $media->id) {
                        $references[] = ['type' => 'draft', 'id' => $draft->id, 'label' => __('media_safety.draft'), 'field' => $draft->content_type.' #'.$draft->content_id];
                        break;
                    }
                }
            }
        }

        return $references;
    }

    private function containsMedia(array $values, Media $media): bool
    {
        foreach ($values as $key => $value) {
            if (is_array($value) && $this->containsMedia($value, $media)) {
                return true;
            }
            if (! is_array($value) && in_array($key, ['image_id', 'media_id', 'featured_image'], true) && (string) $value === (string) $media->id) {
                return true;
            }
            if (is_string($value) && str_contains($value, (string) parse_url($media->url(), PHP_URL_PATH))) {
                return true;
            }
            if ($key === 'html' && is_string($value) && in_array((int) $media->id, app(CompositionHtmlSanitizer::class)->mediaIds($value), true)) {
                return true;
            }
        }

        return false;
    }
}
