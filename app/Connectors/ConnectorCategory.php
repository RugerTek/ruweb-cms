<?php

namespace App\Connectors;

/**
 * What a connector is for, as the administrator groups them in Admin > Connectors (one heading per category, in the
 * order of the cases). Connectors is the showcase of everything external and its status; the settings of each one
 * live in a single place (Meta, the Meta Pixel and the Amiba chatbot on their detail, Bancard in Admin > Payments and
 * shipping).
 */
enum ConnectorCategory: string
{
    /** Platforms where the products are shown or sold (Meta). The usual category of a feed connector. */
    case SalesChannel = 'sales_channel';

    /** Ways to take payments on the site (Bancard). */
    case Payments = 'payments';

    /** Advertising and measurement: what tells an ad platform what visitors do on the site (Meta Pixel). */
    case Marketing = 'marketing';

    /** Talking to the visitors of the site: chat and chatbots (Amiba). */
    case CustomerService = 'customer_service';

    /** Translation key of the group heading (`connectors.category_sales_channel`). */
    public function label(): string
    {
        return 'connectors.category_'.$this->value;
    }
}
