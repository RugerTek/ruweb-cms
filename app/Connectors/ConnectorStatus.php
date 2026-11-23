<?php

namespace App\Connectors;

/** Card state (PLAN.md §4.1), computed by ConnectorRegistry::status(). */
enum ConnectorStatus: string
{
    /** Requirements met, not active: the card offers "Activate". */
    case Available = 'available';

    /** A requirement is unmet (even if the connector was active): the card says what is missing. */
    case NeedsAction = 'needs_action';

    /** Active and with every requirement met: the feed answers. */
    case Connected = 'connected';

    /** Translation key of the chip (`connectors.status_needs_action`). */
    public function label(): string
    {
        return 'connectors.status_'.$this->value;
    }
}
