<?php

namespace App\Http\Middleware;

use App\Models\Page;
use App\Models\Post;
use App\Services\Content\ContentEditLock;
use App\Support\AdminCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectLiveEditor
{
    public function __construct(private ContentEditLock $locks) {}

    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->route('page') ?? $request->route('post');
        abort_unless($content instanceof Page || $content instanceof Post, 404);

        // Agent-operated pages redirect to history; reading them must not reserve a visual editor lease.
        if ($request->isMethod('GET') && $content instanceof Page
            && ! app(AdminCapabilities::class)->pageVisualEditor($content->template_key)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && $request->header('Sec-Fetch-Dest') === 'iframe') {
            return response(__('admin.editor_nested_blocked'), 403)
                ->header('X-Frame-Options', 'DENY')
                ->header('Content-Security-Policy', "frame-ancestors 'none'");
        }

        return $this->locks->synchronized($content, function () use ($request, $content, $next) {
            $lock = $this->locks->active($content);

            if ($request->isMethod('GET')) {
                if ($lock && ! $this->locks->sameSession($lock, $request)) {
                    return response()->view('admin.editor-locked', [
                        'message' => __('admin.editor_locked_by', ['name' => $lock->user_name]),
                        'backUrl' => route('admin.'.$content->getTable().'.index'),
                    ], 423)->header('X-Frame-Options', 'DENY')
                        ->header('Content-Security-Policy', "frame-ancestors 'none'");
                }

                $request->attributes->set('editorLease', $this->locks->acquire($content, $request));
                $response = $next($request);
                $response->headers->set('X-Frame-Options', 'DENY');
                $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
                $response->headers->set('Cache-Control', 'no-store, private');

                return $response;
            }

            // Existing non-editor admin submissions may save when no editor owns
            // the record. A submitted lease must always be current, even after expiry.
            if (($lock || $request->has('_editor_lock')) && ! $this->locks->owns($lock, $request)) {
                return response()->json(['message' => __('admin.editor_lock_lost')], 423);
            }

            $response = $next($request);
            if ($response->isSuccessful() || $response->isRedirection()) {
                if ($request->isMethod('DELETE')) {
                    $this->locks->query($content)->delete();
                } elseif ($lock) {
                    $this->locks->renew($content);
                }
            }

            return $response;
        });
    }
}
