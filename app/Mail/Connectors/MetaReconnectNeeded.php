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
 * To the administrators (App\Listeners\Connectors\SendMetaReconnectMail): Meta cut the «Conectar con Facebook»
 * connection, the products stopped updating in the Meta catalog, and a «Volver a conectar» button to the Meta
 * connector in the panel (and the sign-in address: panel pages answer 404 without a session). $reason is the text kept as the connection's last error. Panel language; no secret.
 */
class MetaReconnectNeeded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $reason, public ?string $catalogName = null)
    {
        $this->locale(OrderPlacedStore::panelLocale());
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: OrderPlacedCustomer::sender(),
            subject: __('meta_health.mail_subject', ['site' => OrderPlacedCustomer::siteName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.connectors.meta-reconnect-needed',
            with: [
                'reason' => $this->reason,
                'catalogName' => $this->catalogName,
                'siteName' => OrderPlacedCustomer::siteName(),
                'reconnectUrl' => route('admin.connectors.show', ['connector' => MetaCatalogConnector::KEY]),
                // Panel pages answer 404 without a session: the email also says where to sign in first.
                'loginUrl' => route('admin.login'),
            ],
        );
    }
}
