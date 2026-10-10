<?php

namespace App\Http\Controllers;

use App\Mcp\ContentResponse;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Services\Content\ContentSchemaRegistry;
use App\Services\Content\LocaleOperations;
use App\Services\Content\TranslationOperations;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Pages\PageOperations;
use App\Services\Posts\PostOperations;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class McpContentController extends Controller
{
    public function schema(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, config('cms.roles', []), true), 403);
        $data = Validator::make($request->all(), ['type' => ['required', Rule::in(['pages', 'posts'])], 'template_key' => ['required_if:type,pages', Rule::in(array_keys(config('cms.templates', [])))]])->validate();
        $schema = $data['type'] === 'pages' ? app(ContentSchemaRegistry::class)->forPageTemplate($data['template_key']) : config('cms.content_schemas.post');

        return response()->json(['data' => ['type' => $data['type'], 'template_key' => $data['template_key'] ?? null, 'schema' => $schema, 'locales' => Locale::orderBy('sort_order')->get(['code', 'name', 'is_base', 'is_active', 'is_public']),
            'rules' => ['translatable' => 'Translate visible text.', 'preserve' => 'Copy unchanged.', 'remap' => 'Use an equivalent translated internal record; never silently link to base-language content.', 'localized_media' => 'Keep or replace with a valid media ID; localize metadata separately.',
                'revision' => 'Send expected_revision from the latest read. A stale revision returns 409; read and merge before retry.', 'retry' => 'Use operation_key for draft creation. Retry identical input; reusing it for different input returns 409.', 'publication' => 'Draft edits reject published records. Publication and scheduling require an admin and full credential scope.'],
            'example' => $data['type'] === 'pages' ? ['title' => 'Example', 'slug' => 'example', 'template_key' => $data['template_key'], 'locale' => Locale::baseCode(), 'content_json' => ['meta' => ['title' => 'Example', 'description' => 'Example description'], 'sections' => new \stdClass], 'operation_key' => 'example-draft-1'] : ['title' => 'Example', 'slug' => 'example', 'locale' => Locale::baseCode(), 'content' => '<p>Example</p>', 'operation_key' => 'example-post-1']]]);
    }

    public function locales(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, config('cms.roles', []), true), 403);

        return response()->json(['data' => ['installed' => Locale::orderBy('sort_order')->get(['code', 'name', 'is_base', 'is_active', 'is_public']), 'catalog' => Locale::catalog()]]);
    }

    public function configureLocales(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), ['install' => ['sometimes', 'string'], 'locales' => ['sometimes', 'array']])->validate();
        abort_unless($request->user()->isAdmin(), 403);
        EditorialActor::run($request->user(), fn () => app(ContentRevisionLedger::class)->transaction(function () use ($request, $data) {
            if (isset($data['install'])) {
                app(LocaleOperations::class)->install($request->user(), $data['install']);
            }
            if (isset($data['locales'])) {
                app(LocaleOperations::class)->update($request->user(), $data['locales']);
            }

        }));

        return $this->locales($request);
    }

    public function translation(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($type, $id);
        $data = Validator::make($request->all(), ['locale' => ['required', 'string']])->validate();
        $result = app(TranslationOperations::class)->create($request->user(), $model, $data['locale']);

        return $this->detail($request, $result);
    }

    public function changes(Request $request, string $type, int $id): JsonResponse
    {
        return response()->json(['data' => app(TranslationOperations::class)->changes($request->user(), $this->model($type, $id), $request->all())]);
    }

    public function acknowledge(Request $request, string $type, int $id): JsonResponse
    {
        return $this->detail($request, app(TranslationOperations::class)->acknowledge($request->user(), $this->model($type, $id), $request->all()));
    }

    public function publication(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($type, $id);
        $data = Validator::make($request->all(), ['action' => ['required', Rule::in(['schedule', 'unpublish'])], 'published_at' => ['required_if:action,schedule', 'nullable', 'date', 'after:now'], 'expected_revision' => ['nullable', 'string', 'size:64']])->validate();
        if ($model instanceof Page) {
            $result = app(PageOperations::class)->setPublication($request->user(), $model, $data['action'] === 'schedule' ? $data['published_at'] : null, $data['expected_revision'] ?? null);
        } else {
            $ops = app(PostOperations::class);
            $result = $data['action'] === 'schedule' ? $ops->schedule($request->user(), $model, $data['published_at'], $data['expected_revision'] ?? null) : $ops->unpublish($request->user(), $model, $data['expected_revision'] ?? null);
        }

        return $this->detail($request, $result);
    }

    public function preview(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($type, $id);
        if ($model instanceof Post) {
            $this->authorizeForUser($request->user(), 'update', $model);
        } else {
            abort_unless(in_array($request->user()->role, config('cms.roles', []), true), 403);
        }
        $data = Validator::make($request->all(), ['hours' => ['sometimes', 'integer', 'min:1', 'max:168']])->validate();
        $token = Str::random(64);
        app(EditorialHistory::class)->query($model, 'editorial_preview_links')->insert(['content_type' => $type, 'content_id' => $id, 'token_hash' => hash('sha256', $token), 'version_id' => null, 'user_id' => $request->user()->id, 'expires_at' => now()->addHours($data['hours'] ?? 1), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['url' => route('editorial.preview', $token), 'expires_at' => now()->addHours($data['hours'] ?? 1)->toISOString()]]);
    }

    public function readField(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($type, $id);
        if ($model instanceof Post) {
            $this->authorizeForUser($request->user(), 'view', $model);
        } else {
            abort_unless(in_array($request->user()->role, config('cms.roles', []), true), 403);
        }
        $data = Validator::make($request->all(), ['field' => ['required', 'string', 'max:500', 'regex:/^(title|slug|excerpt|content|content_json(?:\.[a-zA-Z0-9_-]+)*|meta_json(?:\.[a-zA-Z0-9_-]+)*)$/'], 'offset' => ['sometimes', 'integer', 'min:0'], 'length' => ['sometimes', 'integer', 'min:1', 'max:8192']])->validate();
        $value = data_get($model->attributesToArray(), $data['field']);
        abort_unless(is_string($value) || is_array($value), 422, 'Select a string or structured editorial field.');
        $offset = $data['offset'] ?? 0;
        $length = $data['length'] ?? 8192;

        if (is_array($value)) {
            $length = min(100, $length);

            return response()->json(['data' => ContentResponse::bounded(['field' => $data['field'], 'value' => array_slice($value, $offset, $length, true), 'offset' => $offset, 'length' => $length, 'total_items' => count($value), 'has_more' => $offset + $length < count($value), 'revision' => app(EditorialHistory::class)->fingerprint($model)])]);
        }

        return response()->json(['data' => ['field' => $data['field'], 'value' => mb_substr($value, $offset, $length), 'offset' => $offset, 'length' => $length, 'total_characters' => mb_strlen($value), 'has_more' => $offset + $length < mb_strlen($value), 'revision' => app(EditorialHistory::class)->fingerprint($model)]]);
    }

    private function model(string $type, int $id): Page|Post
    {
        abort_unless(in_array($type, ['pages', 'posts'], true), 404);

        return ($type === 'pages' ? Page::class : Post::class)::findOrFail($id);
    }

    private function detail(Request $request, Page|Post $model): JsonResponse
    {
        return $model instanceof Page ? app(McpController::class)->pagesGet($request, $model) : app(McpController::class)->postsGet($request, $model);
    }
}
