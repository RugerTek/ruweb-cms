<?php

namespace App\Jobs;

use App\Services\Marketing\MetaConversionsApi;
use App\Services\Marketing\MetaPixel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one Meta Pixel server event to the Conversions API (App\Services\Marketing\MetaPixel::dispatch()). The payload
 * holds the event only, with contact details already hashed; the pixel ID, the access token and the test events code
 * are read when it runs, so the token never sits in the queue and a connector turned off meanwhile sends nothing.
 * Connection errors, 5xx and 429 are retried; any other answer is logged (event name, HTTP status, Meta's message and
 * trace id: no personal data, no token) and dropped. It never throws to the caller.
 */
class SendMetaPixelEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    /** @param  array<string, mixed>  $event */
    public function __construct(public readonly array $event) {}

    public function handle(MetaPixel $pixel, MetaConversionsApi $api): void
    {
        $name = (string) ($this->event['event_name'] ?? '');
        try {
            $pixelId = $pixel->pixelId();
            $token = $pixel->accessToken();
            if ($pixelId === null || $token === null) {
                return;
            }
            $result = $api->send($pixelId, $token, [$this->event], $pixel->testEventCode());
        } catch (Throwable $e) {
            $pixel->logFailure($name, $e);

            return;
        }
        if ($result->ok) {
            return;
        }
        if ($result->retryable && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        }
        Log::warning('Meta Conversions API did not accept an event.', [
            'event' => $name,
            'status' => $result->status,
            'message' => $result->message,
            'fbtrace_id' => $result->traceId,
        ]);
    }
}
