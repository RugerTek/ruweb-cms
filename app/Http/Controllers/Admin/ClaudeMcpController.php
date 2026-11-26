<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\McpApiKey;
use App\Support\SiteTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClaudeMcpController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.claude-mcp.index', [
            'mcpUrl' => rtrim(config('app.url'), '/').'/mcp/rpc',
            'user' => $request->user(),
            'apiKeys' => $request->user()->mcpApiKeys()->latest()->get(),
            'newApiKey' => session('new_mcp_api_key'),
        ]);
    }

    public function generateMcpApiKey(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', Rule::in(['full', 'read-only', 'draft-only', 'custom-code'])],
            'expires_at' => ['nullable', 'date'],
        ]);
        // The datetime-local value is in the site zone; the expiry is checked and stored in UTC.
        $expiresAt = SiteTime::toStorage($validated['expires_at'] ?? null);
        Validator::make(['expires_at' => $expiresAt], ['expires_at' => ['nullable', 'date', 'after:now']])->validate();
        abort_if(($validated['scope'] ?? null) === 'custom-code' && ! $request->user()->canManageCustomCode(), 403);
        $plainApiKey = McpApiKey::issue($request->user(), $validated['name'] ?? __('security.key_default'),
            $validated['scope'] ?? 'read-only', $expiresAt);

        return redirect()
            ->route('admin.claude-mcp.index')
            ->with('success', __('admin.mcp_api_key_generated'))
            ->with('new_mcp_api_key', $plainApiKey);
    }

    public function revokeKey(Request $request, McpApiKey $key): RedirectResponse
    {
        abort_unless($key->user_id === $request->user()->id, 403);
        $key->update(['revoked_at' => now()]);

        return back()->with('success', __('admin.mcp_api_key_revoked'));
    }

    public function revokeMcpApiKey(Request $request): RedirectResponse
    {
        $request->user()->revokeMcpApiKey();
        $request->user()->mcpApiKeys()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return redirect()
            ->route('admin.claude-mcp.index')
            ->with('success', __('admin.mcp_api_key_revoked'));
    }
}
