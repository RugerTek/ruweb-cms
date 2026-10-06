<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Services\Content\ContentEditLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EditorLockController extends Controller
{
    public function __construct(private ContentEditLock $locks) {}

    public function heartbeat(Request $request, string $type, int $id): JsonResponse
    {
        $content = $this->content($type, $id);

        return $this->locks->synchronized($content, function () use ($content, $request) {
            if (! $this->locks->owns($this->locks->active($content), $request)) {
                return response()->json(['message' => __('admin.editor_lock_lost')], 423);
            }

            return response()->json(['expiresAt' => $this->locks->renew($content)]);
        });
    }

    public function release(Request $request, string $type, int $id): Response
    {
        $content = $this->content($type, $id);

        return $this->locks->synchronized($content, function () use ($content, $request) {
            if ($this->locks->owns($this->locks->active($content), $request)) {
                $this->locks->query($content)->delete();
            }

            return response()->noContent();
        });
    }

    private function content(string $type, int $id): Page|Post
    {
        $class = match ($type) {
            'pages' => Page::class,
            'posts' => Post::class,
            default => abort(404),
        };
        $content = $class::findOrFail($id);
        if ($content instanceof Post) {
            $this->authorize('update', $content);
        }

        return $content;
    }
}
