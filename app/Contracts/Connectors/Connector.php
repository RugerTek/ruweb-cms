<?php

namespace App\Contracts\Connectors;

use App\Connectors\ConnectorCategory;
use App\Connectors\ConnectorRequirement;

/**
 * A connector the administrator turns on from Admin > Connectors (PLAN.md §4.2). Implementations are listed in
 * `cms.connectors` (key => class), resolved through the container by App\Connectors\ConnectorRegistry and share the
 * `connectors` table (App\Models\Connector): adding one needs a class, a config entry, `lang/{es,en}/connectors.php`
 * keys and tests, never a migration. State changes go through App\Services\Connectors\ConnectorOperations.
 */
interface Connector
{
    /** Registry key, equal to its `cms.connectors` key: lowercase letters, digits and `_` (`meta`). */
    public function key(): string;

    /** Translation key of the name shown on the card (`connectors.meta_label`). */
    public function label(): string;

    /** Translation key of the one-line description shown on the card. */
    public function description(): string;

    /** Blade view rendering the card icon (`admin.connectors.icons.meta`). */
    public function icon(): string;

    /** Group the card is listed under in Admin > Connectors (a feed connector is usually a sales channel). */
    public function category(): ConnectorCategory;

    /**
     * What the site must have before the connector can work, evaluated on every call (never cached). Any unmet
     * requirement makes the status `needs_action`, blocks ConnectorOperations::activate() and turns the feed off.
     *
     * @return list<ConnectorRequirement>
     */
    public function requirements(): array;

    /**
     * Settings used until the administrator saves their own. Every key the connector reads must be here.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Laravel validation rules for the settings; keys without a rule are dropped when saving.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;
}
