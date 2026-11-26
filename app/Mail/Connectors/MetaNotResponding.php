<?php

namespace App\Mail\Connectors;

use App\Connectors\Meta\MetaCatalogConnector;
use App\Mail\Shop\OrderPlacedCustomer;
use App\Mail\Shop\OrderPlacedStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the administrators (App\Services\Connectors\Meta\MetaUnreachableStreak), once per streak: the daily health check
 * has not reached Meta for $days days. The connection is kept and recovers by itself; the email asks to check the
 * server's connection and links the Meta connector (plus the sign-in address: panel pages answer 404 without a
 * session). Panel language; no secret.
 */
class MetaNotResponding extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public int $days, public ?string $catalogName = null)
    {
        $this->locale(OrderPlacedStore::panelLocale());
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: OrderPlacedCustomer::sender(),
            subject: __('meta_health.unreachable_mail_subject', ['days' => $this->days, 'site' => OrderPlacedCustomer::siteName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.connectors.meta-not-responding',
            with: [
                'days' => $this->days,
                'catalogName' => $this->catalogName,
                'siteName' => OrderPlacedCustomer::siteName(),
                'connectorUrl' => route('admin.connectors.show', ['connector' => MetaCatalogConnector::KEY]),
                'loginUrl' => route('admin.login'),
            ],
        );
    }
}
