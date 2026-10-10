<?php

namespace App\Http\Controllers;

use App\Models\OauthAccessToken;
use App\Models\OauthAuthorizationCode;
use App\Services\Security\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OAuthController extends Controller
{
    public function showAuthorize(Request $request): View|RedirectResponse|JsonResponse
    {
        $error = $this->validateAuthorizeRequest($request);
        if ($error) {
            return response()->json(['error' => $error], 400);
        }

        // If the user already has an active session, issue the code immediately
        $sessionRevoked = DB::table('admin_sessions')->where('id', hash('sha256', $request->session()->getId()))->whereNotNull('revoked_at')->exists();
        if ($request->attributes->get('oauth_authorize_parameters')['scope'] !== 'custom-code' && ! $sessionRevoked && Auth::check() && in_array(Auth::user()->role, config('cms.roles', ['admin', 'editor']))
            && (int) $request->session()->get('security_version', 0) === (int) Auth::user()->security_version
            && (! Auth::user()->hasTwoFactor() || $request->session()->get('two_factor_passed') === Auth::id())) {
            return $this->issueAuthCode($request, Auth::user());
        }

        return view('oauth.authorize', [
            'response_type' => $request->attributes->get('oauth_authorize_parameters')['response_type'],
            'client_id' => $request->attributes->get('oauth_authorize_parameters')['client_id'],
            'redirect_uri' => $request->attributes->get('oauth_authorize_parameters')['redirect_uri'],
            'code_challenge' => $request->attributes->get('oauth_authorize_parameters')['code_challenge'],
            'code_challenge_method' => $request->attributes->get('oauth_authorize_parameters')['code_challenge_method'],
            'state' => $request->attributes->get('oauth_authorize_parameters')['state'],
            'scope' => $request->attributes->get('oauth_authorize_parameters')['scope'],
        ]);
    }

    public function handleAuthorize(Request $request): RedirectResponse|JsonResponse
    {
        $error = $this->validateAuthorizeRequest($request);
        if ($error) {
            return response()->json(['error' => $error], 400);
        }

        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::once(['email' => $request->input('email'), 'password' => $request->input('password')])) {
            return back()->withErrors(['password' => __('admin.invalid_credentials')])->withInput($request->except('password'));
        }

        $user = Auth::user();

        if (! in_array($user->role, config('cms.roles', ['admin', 'editor']))) {
            return back()->withErrors(['password' => __('admin.unauthorized')])->withInput($request->except('password'));
        }

        if ($user->hasTwoFactor() && ! app(Totp::class)->verify($user, (string) $request->input('code'))) {
            return back()->withErrors(['code' => __('security.invalid_code')])->withInput($request->except(['password', 'code']));
        }

        if ($request->attributes->get('oauth_authorize_parameters')['scope'] === 'custom-code') {
            abort_unless($user->canManageCustomCode(), 403);
            $request->validate(['custom_code_consent' => ['accepted']]);
        }

        return $this->issueAuthCode($request, $user);
    }

    private function issueAuthCode(Request $request, $user): RedirectResponse
    {
        $code = bin2hex(random_bytes(32));

        // Issuance uses exactly the parameters validated and consented to.
        $parameters = $request->attributes->get('oauth_authorize_parameters');
        $get = fn (string $key, string $default = '') => $parameters[$key] ?? $default;

        OauthAuthorizationCode::create([
            'code' => $code,
            'user_id' => $user->id,
            'client_id' => $get('client_id'),
            'scope' => $get('scope', 'full'),
            'redirect_uri' => $get('redirect_uri'),
            'code_challenge' => $get('code_challenge'),
            'code_challenge_method' => $get('code_challenge_method'),
            'expires_at' => now()->addSeconds(config('oauth.auth_code_ttl', 300)),
        ]);

        $redirectUri = $get('redirect_uri')
            .'?code='.$code
            .'&state='.rawurlencode($get('state'));

        return redirect()->away($redirectUri);
    }

    public function token(Request $request): JsonResponse
    {
        $request->validate([
            'grant_type' => ['required', 'string'],
            'code' => ['required', 'string'],
            'redirect_uri' => ['required', 'string'],
            'client_id' => ['required', 'string'],
            'code_verifier' => ['required', 'string'],
        ]);

        if ($request->input('grant_type') !== 'authorization_code') {
            return response()->json(['error' => 'unsupported_grant_type'], 400);
        }

        $authCode = OauthAuthorizationCode::where('code', $request->input('code'))->first();
        if (! $authCode || $authCode->isUsed() || $authCode->isExpired()
            || $authCode->redirect_uri !== $request->input('redirect_uri')
            || $authCode->client_id !== $request->input('client_id')) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $request->input('code_verifier'), true)), '+/', '-_'), '=');
        if (! hash_equals($authCode->code_challenge, $computed)) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }
        return DB::transaction(function () use ($authCode) {
            // The first transactional query atomically claims and locks the immutable code.
            // Avoid a shared read lock before this write (important for concurrent SQLite exchanges).
            $claimed = OauthAuthorizationCode::whereKey($authCode->id)->whereNull('used_at')
                ->where('expires_at', '>', now())->update(['used_at' => now()]);
            if ($claimed !== 1) {
                return response()->json(['error' => 'invalid_grant'], 400);
            }
            $rawToken = bin2hex(random_bytes(48));
            OauthAccessToken::create([
                'token' => hash('sha256', $rawToken),
                'user_id' => $authCode->user_id,
                'client_id' => $authCode->client_id,
                'scope' => $authCode->scope ?? 'full',
                'expires_at' => now()->addSeconds(config('oauth.access_token_ttl', 28800)),
            ]);

            return response()->json([
                'access_token' => $rawToken,
                'token_type' => 'Bearer',
                'scope' => $authCode->scope ?? 'full',
                'expires_in' => config('oauth.access_token_ttl', 28800),
            ]);
        }, 3);
    }

    public function metadata(): JsonResponse
    {
        $base = rtrim(config('app.url'), '/');

        return response()->json([
            'issuer' => $base,
            'authorization_endpoint' => $base.'/authorize',
            'token_endpoint' => $base.'/token',
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'grant_types_supported' => ['authorization_code'],
            'scopes_supported' => ['full', 'read-only', 'draft-only', 'custom-code'],
        ]);
    }

    private function validateAuthorizeRequest(Request $request): ?string
    {
        $keys = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope'];
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        foreach ($keys as $key) {
            if ($request->query->has($key) && array_key_exists($key, $body) && $request->query($key) !== $body[$key]) return 'invalid_request';
        }
        if (! in_array($request->input('scope', 'full'), ['full', 'read-only', 'draft-only', 'custom-code'], true)) {
            return 'invalid_scope';
        }

        if ($request->input('response_type') !== 'code') {
            return 'unsupported_response_type';
        }

        $clientId = $request->input('client_id');
        $clients = config('oauth.clients', []);

        if (! isset($clients[$clientId])) {
            return 'unknown_client';
        }

        $redirectUri = $request->input('redirect_uri');
        $allowedUris = $clients[$clientId]['redirect_uris'] ?? [];

        if (! in_array($redirectUri, $allowedUris, true)) {
            return 'invalid_redirect_uri';
        }

        if ($request->input('code_challenge_method') !== 'S256') {
            return 'invalid_request';
        }

        if (! $request->input('code_challenge')) {
            return 'invalid_request';
        }

        $request->attributes->set('oauth_authorize_parameters', $request->only($keys) + ['scope' => 'full', 'state' => '']);
        return null;
    }
}
