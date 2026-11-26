<?php

namespace App\Connectors\AmibaChat;

use App\Connectors\ConnectorAction;
use App\Connectors\ConnectorCategory;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequirement;
use App\Contracts\Connectors\Connector;
use Closure;

/**
 * Amiba chatbot, level 1 (`cms.connectors.amiba_chat`, docs/operations/connectors.md#amiba-chatbot-card): the client
 * pastes the code of the widget created in Amiba, turns the connector on and the public pages load Amiba's floating
 * chat (`loader.js`, rendered by resources/views/partials/amiba-chat.blade.php). Nothing changes on the Amiba side and
 * no secret is involved: the widget handle is public (it travels in the HTML of every page).
 *
 * Settings (validated by rules(), editable from the panel and MCP): `handle` (the widget's site key; normalizeSettings()
 * also accepts the whole `<script …>` snippet Amiba hands out and keeps only the handle) and `hide_on_checkout`.
 * The runtime lives in App\Services\AmibaChat\AmibaChat; the "Test" button in AmibaChatOperations.
 */
class AmibaChatConnector implements Connector
{
    public const KEY = 'amiba_chat';

    /** Requirement: a widget handle saved. */
    public const REQUIREMENT_HANDLE = 'amiba_handle';

    /**
     * Amiba creates every widget handle as `Guid.CreateVersion7().ToString()` (andromeda-api,
     * WorkflowsWebhookService::CreateWebhook): 36 characters, lowercase hex in 8-4-4-4-12 groups.
     */
    public const HANDLE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Where the loader reads the handle from in the snippet (amiba-webchat public/loader.js). */
    private const SNIPPET_ATTRIBUTE = '/data-amiba-widget\s*=\s*(["\']?)([^"\'\s>]+)\1/i';

    /** The iframe embed Amiba also offers (`https://widget.amiba.io/w/HANDLE`). */
    private const IFRAME_PATH = '#/w/([0-9A-Fa-f-]{36})(?:[/?"\'\s]|$)#';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'amiba_chat.label';
    }

    public function description(): string
    {
        return 'amiba_chat.description';
    }

    public function icon(): string
    {
        return 'admin.connectors.icons.amiba';
    }

    public function category(): ConnectorCategory
    {
        return ConnectorCategory::CustomerService;
    }

    /** Only the widget code: the chat works on any site, with or without the shop. */
    public function requirements(): array
    {
        $met = self::validHandle(app(ConnectorRegistry::class)->settings(self::KEY)['handle'] ?? null);

        return [new ConnectorRequirement(
            self::REQUIREMENT_HANDLE,
            $met,
            'amiba_chat.requirement_handle',
            $met ? null : new ConnectorAction('admin.connectors.show', 'amiba_chat.action_configure', null, 'GET'),
        )];
    }

    public function defaults(): array
    {
        return [
            'handle' => '',
            'hide_on_checkout' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'handle' => ['nullable', 'string', 'max:128', self::handleRule()],
            'hide_on_checkout' => ['boolean'],
        ];
    }

    /**
     * Settings as typed by a person or an agent, before rules(): the pasted snippet (or iframe) becomes its handle and
     * `hide_on_checkout` a real boolean. Unknown keys pass through untouched (the caller drops them).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalizeSettings(array $settings): array
    {
        if (array_key_exists('handle', $settings)) {
            $settings['handle'] = self::extractHandle($settings['handle']);
        }
        if (array_key_exists('hide_on_checkout', $settings) && is_string($settings['hide_on_checkout'])) {
            $flag = filter_var($settings['hide_on_checkout'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $settings['hide_on_checkout'] = $flag ?? $settings['hide_on_checkout'];
        }

        return $settings;
    }

    /**
     * The handle inside what was pasted: the handle alone, the floating widget `<script … data-amiba-widget="…">` or
     * the iframe embed. Anything else comes back trimmed, so rules() can reject it with a clear message.
     */
    public static function extractHandle(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);
        if (preg_match(self::SNIPPET_ATTRIBUTE, $value, $match) === 1) {
            $value = $match[2];
        } elseif (preg_match(self::IFRAME_PATH, $value, $match) === 1) {
            $value = $match[1];
        }

        // Amiba writes handles in lowercase and looks them up as written; an uppercase copy would not be found.
        return preg_match('/^[0-9A-Fa-f-]{36}$/', $value) === 1 ? strtolower($value) : $value;
    }

    public static function validHandle(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HANDLE_PATTERN, $value) === 1;
    }

    /** Rejects what is not a widget code, in the panel's words (also through MCP). */
    private static function handleRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value !== '' && ! self::validHandle($value)) {
                $fail(__('amiba_chat.error_handle_format'));
            }
        };
    }
}
