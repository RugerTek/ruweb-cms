<?php

namespace App\Http\Middleware;

use App\Models\SupportSession;
use App\Services\SupportAccess\SupportActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * During a support session (EnforceSupportSession set the `support_session` attribute) records every admin write
 * and every page view (a full GET, not the editor's background calls) with its response status. See
 * docs/operations/support-access.md#what-is-recorded.
 */
class RecordSupportActivity
{
    public function __construct(private readonly SupportActivityRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $support = $request->attributes->get('support_session');
        if (! $support instanceof SupportSession || ! $this->recordable($request)) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->recorder->record($request, $support, 500);

            throw $exception;
        }
        $this->recorder->record($request, $support, $response->getStatusCode());

        return $response;
    }

    private function recordable(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return ! $request->isMethod('OPTIONS');
        }

        return $request->isMethod('GET') && ! $request->ajax() && ! $request->expectsJson() && ! $request->prefetch();
    }
}
