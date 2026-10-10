<?php

namespace App\Http\Middleware;

use App\Services\Content\ContentEditLock;
use App\Services\Editorial\EditorialHistory;
use Closure;
use Illuminate\Http\Request;

class ProtectEditorialMutation
{
    public function handle(Request $request, Closure $next)
    {
        $model = app(EditorialHistory::class)->model($request->route('type'), (int) $request->route('id'));
        $locks = app(ContentEditLock::class);

        return $locks->synchronized($model, function () use ($request, $model, $locks, $next) {
            $lock = $locks->active($model);
            abort_if($lock && ! $locks->owns($lock, $request), 423, __('admin.editor_lock_lost'));

            if ($request->filled('_editor_base')) {
                abort_unless(hash_equals(app(EditorialHistory::class)->fingerprint($model->fresh()), $request->input('_editor_base')), 409, __('editorial.conflict'));
            }

            return $next($request);
        });
    }
}
