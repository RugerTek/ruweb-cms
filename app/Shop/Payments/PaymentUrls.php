<?php

namespace App\Shop\Payments;

use InvalidArgumentException;

/**
 * Where the customer returns after an online payment form (Bancard return_url / cancel_url). Absolute http(s)
 * URLs; the cancel URL defaults to the return URL (Bancard does the same when it is omitted).
 */
final class PaymentUrls
{
    public readonly string $cancelUrl;

    /**
     * @throws InvalidArgumentException when a URL is not absolute http(s)
     */
    public function __construct(
        public readonly string $returnUrl,
        ?string $cancelUrl = null,
    ) {
        $this->cancelUrl = $cancelUrl ?? $returnUrl;

        foreach ([$this->returnUrl, $this->cancelUrl] as $url) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Payment URLs must be absolute http(s) URLs.');
            }
        }
    }
}
