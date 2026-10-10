<?php

namespace App\Http\Middleware;

use App\Support\AdminCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLiveEditor
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(AdminCapabilities::class)->liveEditor(), 404, __('admin.profile_editor_unavailable'));

        return $next($request);
    }
}
