<?php

namespace App\Services\SupportAccess;

use App\Connectors\ConnectorRegistry;
use App\Models\SupportAudit;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Turns a support_audit row into one readable line for the client («Editó el producto «Remera»», «Cambió los
 * ajustes del sitio», «Vio el conector Chatbot Amiba»). Built from the route name and the recorded subject only: the
 * row has no values to show.
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

    /** Screens opened (GET) that are told by name: route => support.summary.views.<key>. */
    private const VIEWS = [
        'admin.dashboard' => 'dashboard',
        'admin.analytics.index' => 'analytics',
        'admin.claude-mcp.index' => 'mcp',
        'admin.connectors.index' => 'connectors',
        'admin.connectors.meta.catalog' => 'meta_catalog',
        'admin.content.index' => 'content',
        'admin.custom-code.index' => 'custom_code',
        'admin.developer-tools.index' => 'developer_tools',
        'admin.editorial-control.index' => 'editorial_control',
        'admin.editorial.calendar' => 'calendar',
        'admin.email.index' => 'email',
        'admin.footer.index' => 'footer',
        'admin.header.index' => 'header',
        'admin.languages.index' => 'languages',
        'admin.media.index' => 'media',
        'admin.media.health' => 'media_health',
        'admin.menus.index' => 'menus',
        'admin.orders.index' => 'orders',
        'admin.pages.index' => 'pages',
        'admin.posts.index' => 'posts',
        'admin.products.index' => 'products',
        'admin.profile.index' => 'profile',
        'admin.redirects.index' => 'redirects',
        'admin.settings.index' => 'settings',
        'admin.settings.shop' => 'shop_settings',
        'admin.shop-payments.edit' => 'payments',
        'admin.submissions.index' => 'submissions',
        'admin.support-activity.index' => 'support_activity',
        'admin.taxonomies.index' => 'taxonomies',
        'admin.users.index' => 'users',
    ];

    private const CREATED = ['store', 'create', 'upload', 'import', 'translate'];

    private const UPDATED = ['update', 'sync', 'settings', 'restore', 'publish', 'draft', 'activate', 'deactivate', 'rotate', 'notes', 'note'];

    private const DELETED = ['destroy', 'delete', 'revoke', 'discard', 'clear', 'prune', 'disconnect'];

    public function __construct(private readonly ConnectorRegistry $connectors) {}

    public function describe(SupportAudit $audit): string
    {
        if (! $audit->isWrite()) {
            return $this->view($audit);
        }
        $line = $this->write($audit);
        if ($audit->status !== null && $audit->status >= 400) {
            $line .= ' '.__('support.summary.not_applied', ['status' => $audit->status]);
        }

        return $line;
    }

    /** «Vio los ajustes del sitio», «Vio el producto «Remera»», «Abrió el formulario para crear una página». */
    private function view(SupportAudit $audit): string
    {
        $route = (string) $audit->route_name;
        if (isset(self::VIEWS[$route])) {
            return __('support.summary.viewed_screen', ['screen' => __('support.summary.views.'.self::VIEWS[$route])]);
        }
        $verb = $route === '' ? '' : substr($route, strrpos($route, '.') + 1);
        if ($verb === 'create') {
            $type = $this->typeFromRoute($route);
            $key = 'support.new.'.$type;

            return __('support.summary.viewed_create', ['what' => $type !== null && Lang::has($key) ? __($key) : __('support.new.item')]);
        }
        $subject = $audit->subject_type !== null ? $this->subject($audit) : null;
        if ($subject !== null) {
            return __('support.summary.viewed_screen', ['screen' => $subject]);
        }

        return __('support.summary.viewed', ['path' => $audit->path]);
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

    /** «el producto «Remera»», «la página #4», «el conector Chatbot Amiba», or null when the row names nothing. */
    private function subject(SupportAudit $audit): ?string
    {
        $type = $audit->subject_type;
        if ($type === null) {
            $type = $this->typeFromRoute((string) $audit->route_name);
            if ($type === null) {
                return null;
            }
        }
        if ($type === 'connector' && filled($audit->subject_label)) {
            return __('support.summary.subject_connector', ['label' => $this->connectorName((string) $audit->subject_label)]);
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

    /** The name the panel gives a connector key («amiba_chat» → «Chatbot Amiba»); the key itself if unknown now. */
    private function connectorName(string $key): string
    {
        try {
            return $this->connectors->has($key) ? (string) __($this->connectors->get($key)->label()) : $key;
        } catch (Throwable) {
            return $key;
        }
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
