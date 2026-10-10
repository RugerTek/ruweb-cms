<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Editorial\Previewable;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Editorial\EditorialHistory;
use Illuminate\Http\Request;

/** Read-only operational views. The deployment profile never grants content permission. */
class ContentStatusController extends Controller
{
    public function modelClass(string $type): string
    {
        $class = $type === 'pages' ? Page::class : (config('cms.collections', [])[$type]['model'] ?? null);
        abort_unless(is_string($class) && is_subclass_of($class, Previewable::class), 404);

        return $class;
    }

    private function model(string $type, int $id)
    {
        $class = $this->modelClass($type);
        $model = $class::findOrFail($id);
        // Pages use the existing admin role boundary; registered collections retain their policy.
        if ($class !== Page::class) {
            $this->authorize('view', $model);
        }

        return $model;
    }

    public function index(Request $request, string $type)
    {
        $class = $this->modelClass($type);
        if ($class !== Page::class) {
            $this->authorize('viewAny', $class);
        }
        $query = $class::query()->latest();
        $request->validate(['status' => ['nullable', 'in:draft,published,scheduled,expired'], 'search' => ['nullable', 'string', 'max:255']]);
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$request->input('search').'%')->orWhere('slug', 'like', '%'.$request->input('search').'%'));
        }
        match ($request->input('status')) {
            'draft' => $query->where('status', 'draft'),
            'published' => $query->published(),
            'scheduled' => $query->where('status', 'published')->where('published_at', '>', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            'expired' => $query->where('status', 'published')->where('expires_at', '<=', now()),
            default => null,
        };

        return view('admin.content.index', ['items' => $query->paginate(15)->withQueryString(), 'type' => $type, 'label' => $type === 'pages' ? 'admin.pages' : (config('cms.collections', [])[$type]['label'] ?? 'admin.col_title')]);
    }

    public function show(Request $request, string $type, int $id, EditorialHistory $history)
    {
        $model = $this->model($type, $id);
        $versions = $history->query($model)->latest('id')->paginate(20);
        $version = $request->integer('version') ? $history->query($model)->where('id', $request->integer('version'))->firstOrFail() : null;

        return view('admin.content.show', [
            'model' => $model, 'type' => $type, 'versions' => $versions, 'version' => $version,
            'diff' => $version ? $history->diff(json_decode($version->payload, true), $history->payload($model)) : [],
        ]);
    }

    public function preview(string $type, int $id)
    {
        $model = $this->model($type, $id);
        app()->setLocale($model->locale);

        return response()->view($model->previewView(), $model->previewData() + ['isEditorPreview' => true])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')->header('Cache-Control', 'no-store, private')
            ->header('Content-Security-Policy', "form-action 'none'; frame-ancestors 'self'")
            ->header('Referrer-Policy', 'no-referrer')->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
