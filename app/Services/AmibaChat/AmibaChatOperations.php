<?php

namespace App\Services\AmibaChat;

use App\Connectors\AmibaChat\AmibaChatConnector;
use App\Connectors\ConnectorRegistry;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The "Test" button of the Amiba chatbot detail: asks Amiba, from the server, for the public configuration of the saved
 * widget (GET {cms.amiba_chat.api_url}/webchat/{handle}/config, the call the widget itself makes) and explains the
 * answer. That endpoint answers 404 for a handle that does not exist or whose Web Chat is off, and 200 with the
 * widget's `allowedOrigins` otherwise; Amiba refuses a site outside that list later, when the visitor's chat starts
 * (POST /session → 403 `origin_not_allowed`), so the check against this site's origin is done here, with Amiba's rule
 * (empty list = any site; otherwise an exact scheme://host[:port] match). A 403 `origin_not_allowed` is understood too.
 * Administrators only (`manage-connectors`). Writes nothing.
 */
final class AmibaChatOperations
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * @throws AuthorizationException when the actor is not an administrator
     * @throws ValidationException (`test`) when no valid widget code is saved
     */
    public function test(User $actor): AmibaChatTestResult
    {
        Gate::forUser($actor)->authorize(ConnectorOperations::ABILITY);
        $handle = $this->registry->settings(AmibaChatConnector::KEY)['handle'] ?? null;
        if (! AmibaChatConnector::validHandle($handle)) {
            throw ValidationException::withMessages(['test' => __('amiba_chat.test_needs_handle')]);
        }
        $origin = AmibaChat::siteOrigin();

        try {
            $response = Http::acceptJson()
                ->timeout(max(1, (int) config('cms.amiba_chat.timeout', 8)))
                ->get(rtrim((string) config('cms.amiba_chat.api_url'), '/').'/webchat/'.rawurlencode($handle).'/config');
        } catch (ConnectionException) {
            return new AmibaChatTestResult(AmibaChatTestResult::UNREACHABLE, $origin);
        } catch (Throwable $e) {
            Log::warning('Amiba chat test failed.', ['exception' => $e::class]);

            return new AmibaChatTestResult(AmibaChatTestResult::UNREACHABLE, $origin);
        }

        if ($response->status() === 404) {
            return new AmibaChatTestResult(AmibaChatTestResult::NOT_FOUND, $origin);
        }
        if ($response->status() === 403 && $response->json('error') === 'origin_not_allowed') {
            return new AmibaChatTestResult(AmibaChatTestResult::ORIGIN_NOT_ALLOWED, $origin);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return new AmibaChatTestResult(AmibaChatTestResult::UNEXPECTED, $origin, status: $response->status());
        }

        $allowed = array_values(array_filter((array) $response->json('allowedOrigins', []), 'is_string'));
        $title = is_string($response->json('title')) ? trim($response->json('title')) : '';
        if ($allowed === []) {
            return new AmibaChatTestResult(AmibaChatTestResult::OK_ANY_SITE, $origin, $title);
        }
        $listed = in_array($origin, array_map(fn (string $value) => AmibaChat::normalizeOrigin($value), $allowed), true);

        return new AmibaChatTestResult($listed ? AmibaChatTestResult::OK : AmibaChatTestResult::ORIGIN_NOT_ALLOWED, $origin, $title, $allowed);
    }
}
