<?php

namespace App\Services\Shop;

use App\Connectors\Bancard\BancardConnector;
use App\Connectors\ConnectorRegistry;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Shop\Payments\ManualPaymentProvider;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Support\SiteUrl;
use Illuminate\Validation\ValidationException;

/**
 * The rules of a payment method write, shared by Admin > Payments and shipping (Admin\ShopPaymentsController) and
 * MCP `payment_methods_update` (McpOrderController), so both sides store the same thing (PLAN.md E3.4):
 *
 * - public settings of a manual method: only its fields (ManualPaymentProvider::fieldsFor()), trimmed, a blank value
 *   removes the field, FIELD_MAX characters (INSTRUCTIONS_MAX for `instructions`);
 * - Bancard keys: one environment at a time, encrypted (payment_methods.secrets) and bound to this site's origin; keys
 *   saved under another origin or APP_KEY are never kept next to new ones (R20); the environment defaults to staging;
 * - an enabled method stays complete: the checkout would silently leave out an incomplete one;
 * - the `bancard` connector row mirrors the method and selling online (syncConnector()), also when Settings > Shop
 *   turns selling on or off (ShopModuleOperations::setMode()).
 *
 * The methods change the model without saving it: callers lock, check revisions and save inside
 * CheckoutReadiness::guard(), so a selling site never loses its last payment method.
 */
final class PaymentMethodOperations
{
    /** Longest single-line public field of a manual method. */
    public const FIELD_MAX = 255;

    /** Longest `instructions` text (cash on delivery). */
    public const INSTRUCTIONS_MAX = 2000;

    public function __construct(private readonly PaymentProviderRegistry $providers) {}

    /**
     * Apply public settings to a manual method (other methods have none). Returns the errors keyed
     * "$errorPrefix.<field>"; nothing is thrown so a caller editing several methods can report them together.
     *
     * @param  array<string, mixed>  $input  field => value; null or blank removes the field
     * @return array<string, string>
     */
    public function fillSettings(PaymentMethod $method, array $input, string $errorPrefix = 'settings'): array
    {
        $allowed = $this->publicFields($method);
        $settings = $method->settings_json ?? [];
        $errors = [];
        foreach ($input as $field => $value) {
            if (! in_array((string) $field, $allowed, true)) {
                continue;
            }
            $value = trim((string) $value);
            $max = $field === 'instructions' ? self::INSTRUCTIONS_MAX : self::FIELD_MAX;
            if (mb_strlen($value) > $max) {
                $errors[$errorPrefix.'.'.$field] = __('validation.max.string', ['attribute' => __('checkout.'.$field), 'max' => $max]);
            }
            if ($value === '') {
                unset($settings[$field]);
            } else {
                $settings[$field] = $value;
            }
        }
        $method->settings_json = $settings === [] ? null : $settings;

        return $errors;
    }

    /**
     * Keys of $input that are not public fields of this method (fillSettings() ignores them).
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function ignoredSettings(PaymentMethod $method, array $input): array
    {
        return array_values(array_diff(array_map('strval', array_keys($input)), $this->publicFields($method)));
    }

    /**
     * Public fields of a method: those of its manual kind, none for online providers (their keys are credentials).
     *
     * @return list<string>
     */
    public function publicFields(PaymentMethod $method): array
    {
        return $method->provider === ManualPaymentProvider::KEY ? ManualPaymentProvider::fieldsFor($method->key) : [];
    }

    /**
     * What an enabled method still lacks after a change of `enabled` or its settings (provider words: field names,
     * `credentials`…); empty when it is complete, disabled or untouched. Callers refuse the write when not empty.
     *
     * @return list<string>
     */
    public function incomplete(PaymentMethod $method): array
    {
        if (! $method->enabled || ! $method->isDirty(['enabled', 'settings_json'])) {
            return [];
        }

        return $this->providers->missing($method);
    }

    /** Stored secrets that cannot be used here: unreadable (another APP_KEY) or saved under another origin (R20). */
    public function foreignSecrets(PaymentMethod $method): bool
    {
        return $method->getRawOriginal('secrets') !== null && ($method->secretsUnreadable() || ! $method->isBoundToThisSite());
    }

    /**
     * Store one environment's key pair. A null or blank key keeps the one already stored for that environment.
     *
     * @throws ValidationException (`public_key` / `private_key`) when the resulting pair is incomplete
     */
    public function storeCredentials(PaymentMethod $method, string $environment, ?string $publicKey, ?string $privateKey): void
    {
        $secrets = $this->usableSecrets($method);
        $pair = is_array($secrets[$environment] ?? null) ? $secrets[$environment] : [];
        $public = trim((string) $publicKey) ?: ($pair['public_key'] ?? null);
        $private = trim((string) $privateKey) ?: ($pair['private_key'] ?? null);

        $errors = [];
        if (! is_string($public) || $public === '') {
            $errors['public_key'] = __('checkout.bancard_public_key_invalid');
        }
        if (! is_string($private) || $private === '') {
            $errors['private_key'] = __('checkout.bancard_private_key_required');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $secrets[$environment] = ['public_key' => $public, 'private_key' => $private];
        $this->fillSecrets($method, $secrets);
    }

    /** Remove one environment's key pair (and with it the site binding when no pair is left). */
    public function clearCredentials(PaymentMethod $method, string $environment): void
    {
        $secrets = $this->usableSecrets($method);
        unset($secrets[$environment]);
        $this->fillSecrets($method, $secrets);
    }

    /**
     * Mirror the `bancard` payment method into its connector card (Admin > Connectors): on while Bancard is enabled
     * and every requirement of the card is met (catalog on, Bancard ready, selling online), off otherwise. A no-op
     * when the connector is not registered in this deployment or already in the right state.
     */
    public function syncConnector(User $actor): void
    {
        $connectors = app(ConnectorRegistry::class);
        if (! $connectors->has(BancardConnector::KEY)) {
            return;
        }
        $enabled = (bool) PaymentMethod::query()->where('key', BancardConnector::KEY)->value('enabled');
        $wanted = $enabled && $connectors->unmet(BancardConnector::KEY) === [];
        $active = (bool) $connectors->record(BancardConnector::KEY)?->enabled;

        if ($wanted && ! $active) {
            app(ConnectorOperations::class)->activate($actor, BancardConnector::KEY);
        } elseif (! $wanted && $active) {
            app(ConnectorOperations::class)->deactivate($actor, BancardConnector::KEY);
        }
    }

    /** @return array<string, mixed> stored pairs that may be kept (none when they belong to another site) */
    private function usableSecrets(PaymentMethod $method): array
    {
        return $this->foreignSecrets($method) ? [] : ($method->readSecrets() ?? []);
    }

    /** @param  array<string, mixed>  $secrets */
    private function fillSecrets(PaymentMethod $method, array $secrets): void
    {
        $method->forceFill([
            'secrets' => $secrets === [] ? null : $secrets,
            'bound_origin' => $secrets === [] ? null : SiteUrl::origin(),
            'environment' => $method->environment ?? PaymentMethod::ENVIRONMENT_STAGING,
        ]);
    }
}
