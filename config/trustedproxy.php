<?php

/*
| Proxies whose X-Forwarded-* headers are trusted (scheme, host, port, client IP). Needed behind Cloudflare,
| tunnels and load balancers: without it the app sees plain http and builds http:// asset and form URLs on an
| https site. Laravel's TrustProxies middleware reads `proxies` on every request, so this survives config:cache.
| Applied from bootstrap/app.php (trustProxies). See docs/operations/deploy-cpanel.md and deploy-droplet.md.
|
| TRUSTED_PROXIES: empty = trust none; `*` = trust the address that connects to PHP (a proxy in front of
| the server); otherwise a comma-separated list of IPs/CIDR ranges.
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(array_map(trim(...), explode(',', $proxies)), fn (string $ip): bool => $ip !== '')),
    },
];
