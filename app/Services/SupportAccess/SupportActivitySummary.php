<?php

namespace App\Services\SupportAccess;

use App\Models\SupportAudit;
use Illuminate\Support\Facades\Lang;

/**
 * Turns a support_audit row into one readable line for the client («Editó el producto «Remera»», «Cambió los
 * ajustes del sitio»). Built from the route name and the recorded subject only: the row has no values to show.
 */
class SupportActivitySummary
{
    /** Screens whose single write is best told by name. */
    private const SCREENS = [
        'admin.settings.update' => 'settings',
        'admin.settings.shop.update' => 'shop_settings',
        'admin.header.update' => 'header',
        'admin.footer.update' => 'footer',
        'admin.email.update' => 'email',
        'admin.email.test' => 'email_test',
        'admin.analytics.update' => 'analytics',
        'admin.languages.store' => 'languages',
        'admin.languages.update' => 'languages',
        'admin.cache.refresh' => 'cache',
        'admin.shop-payments.update' => 'payments',
        'admin.logout' => 'logout',
        'admin.support.exit' => 'exit',
    ];

    private const CREATED = ['store', 'create', 'upload', 'import', 'translate'];

    private const UPDATED = ['update', 'sync', 'settings', 'restore', 'publish', 'draft', 'activate', 'deactivate', 'rotate', 'notes', 'note'];

    private const DELETED = ['destroy', 'delete', 'revoke', 'discard', 'clear', 'prune', 'disconnect'];

    public function describe(SupportAudit $audit): string
    {
        if (! $audit->isWrite()) {
            return __('support.summary.viewed', ['path' => $audit->path]);
        }
        $line = $this->write($audit);
        if ($audit->status !== null && $audit->status >= 400) {
            $line .= ' '.__('support.summary.not_applied', ['status' => $audit->status]);
        }

        return $line;
    }

    private function write(SupportAudit $audit): string
    {
        $route = (string) $audit->route_name;
        if (isset(self::SCREENS[$route])) {
            return __('support.summary.screens.'.self::SCREENS[$route]);
        }
        $verb = $route === '' ? '' : substr($route, strrpos($route, '.') + 1);
        $subject = $this->subject($audit);
        if ($subject === null) {
            return __('support.summary.request', ['method' => $audit->method, 'path' => $audit->path]);
        }
        $action = match (true) {
            in_array($verb, self::CREATED, true) => 'created',
            in_array($verb, self::DELETED, true) || $audit->method === 'DELETE' => 'deleted',
            in_array($verb, self::UPDATED, true) || in_array($audit->method, ['PUT', 'PATCH'], true) => 'updated',
            default => 'other',
        };

        return __('support.summary.'.$action, ['subject' => $subject, 'action' => $verb]);
    }

    /** «el producto «Remera»», «la página #4», or null when the row names nothing. */
    private function subject(SupportAudit $audit): ?string
    {
        $type = $audit->subject_type;
        if ($type === null) {
            $type = $this->typeFromRoute((string) $audit->route_name);
            if ($type === null) {
                return null;
            }
        }
        $nounKey = 'support.subjects.'.$type;
        $noun = Lang::has($nounKey) ? __($nounKey) : __('support.subjects.item');
        if (filled($audit->subject_label)) {
            return __('support.summary.subject_named', ['noun' => $noun, 'label' => $audit->subject_label]);
        }
        if (filled($audit->subject_id)) {
            return __('support.summary.subject_id', ['noun' => $noun, 'id' => $audit->subject_id]);
        }

        return $noun;
    }

    /** `admin.products.store` → `product`: the resource segment, singular, for creations without a subject. */
    private function typeFromRoute(string $route): ?string
    {
        $parts = explode('.', $route);
        if (count($parts) < 3 || $parts[0] !== 'admin') {
            return null;
        }
        $resource = str_replace('-', '_', $parts[1]);
        $singular = [
            'pages' => 'page', 'posts' => 'post', 'products' => 'product', 'media' => 'media', 'menus' => 'menu',
            'users' => 'user', 'redirects' => 'redirect', 'taxonomies' => 'taxonomy', 'custom_code' => 'custom_code',
            'orders' => 'order', 'connectors' => 'connector', 'submissions' => 'submission', 'shipping_zones' => 'shipping_zone',
        ];

        return $singular[$resource] ?? null;
    }
}
