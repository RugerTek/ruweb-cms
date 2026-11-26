<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Setting;
use App\Services\Editorial\EditorialInsightsService;
use App\Services\Mail\MailSettings;
use App\Services\Media\MediaHealthService;
use App\Support\AdminLoginPath;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected MediaHealthService $health,
        protected EditorialInsightsService $insights
    ) {
    }

    public function index(Request $request): View
    {
        $showCompleted = $request->boolean('show_completed');
        $editorial = $this->insights->summary();
        $setupTasks = collect($this->setupTasks($request))
            ->sortBy(fn (array $task): int => $task['completed'] ? 1 : 0)
            ->values();
        $issueCounts = collect($editorial['completionIssues'])->keyBy('key');

        return view('admin.dashboard', [
            'mediaCount' => Media::count(),
            'mediaHealth' => $this->health->summary(),
            'setupTasks' => $setupTasks,
            'pendingSetupTasks' => $setupTasks->where('completed', false)->values(),
            'completedSetupTasks' => $setupTasks->where('completed', true)->values(),
            'showCompleted' => $showCompleted,
            'editorialIssueCounts' => $issueCounts,
            'pendingTranslationsCount' => $editorial['pendingTranslationsCount'],
            'hasSecondaryPublicLocales' => $editorial['hasSecondaryPublicLocales'],
            'translationCoverage' => $editorial['translationCoverage'],
        ]);
    }

    /**
     * @return array<int, array{key:string,title:string,description:string,completed:bool,href:?string,action:?string,status_label:string}>
     */
    private function setupTasks(Request $request): array
    {
        $googleTagId = trim((string) (Setting::get('google_tag_id') ?? ''));
        $metaPixelId = trim((string) (Setting::get('meta_pixel_id') ?? ''));
        $searchConsoleToken = trim((string) (Setting::get('search_console_verification_token') ?? ''));
        $siteFavicon = Setting::getLocalized('site_favicon');
        $siteLogo = Setting::getLocalized('site_logo');
        $defaultSocialImage = Setting::getLocalized('default_social_image');
        $adminLoginPathCustomized = AdminLoginPath::isCustomized();
        // Brevo or SMTP (Admin > Email): on, complete and with a sender.
        $mailConfigured = app(MailSettings::class)->active()
            && filled(Setting::get('mail_from_address'))
            && filled(Setting::get('mail_from_name'));
        $serverProtocol = strtoupper((string) $request->server('SERVER_PROTOCOL', ''));
        $http2Enabled = str_contains($serverProtocol, 'HTTP/2');

        return [
            [
                'key' => 'google_tag_id',
                'title' => __('admin.dashboard_task_google_tag_title'),
                'description' => __('admin.dashboard_task_google_tag_description'),
                'completed' => $googleTagId !== '',
                'href' => route('admin.analytics.index'),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $googleTagId !== '' ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'meta_pixel_id',
                'title' => __('admin.dashboard_task_meta_pixel_title'),
                'description' => __('admin.dashboard_task_meta_pixel_description'),
                'completed' => $metaPixelId !== '',
                'href' => route('admin.analytics.index'),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $metaPixelId !== '' ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'search_console_verification_token',
                'title' => __('admin.dashboard_task_search_console_title'),
                'description' => __('admin.dashboard_task_search_console_description'),
                'completed' => $searchConsoleToken !== '',
                'href' => route('admin.analytics.index'),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $searchConsoleToken !== '' ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'site_favicon',
                'title' => __('admin.dashboard_task_favicon_title'),
                'description' => __('admin.dashboard_task_favicon_description'),
                'completed' => $siteFavicon !== null,
                'href' => route('admin.settings.index'),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $siteFavicon !== null ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'site_logo',
                'title' => __('admin.dashboard_task_logo_title'),
                'description' => __('admin.dashboard_task_logo_description'),
                'completed' => $siteLogo !== null,
                'href' => route('admin.settings.index'),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $siteLogo !== null ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'default_social_image',
                'title' => __('admin.dashboard_task_default_social_image_title'),
                'description' => __('admin.dashboard_task_default_social_image_description'),
                'completed' => $defaultSocialImage !== null,
                'href' => route('admin.settings.index'),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $defaultSocialImage !== null ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'admin_login_path',
                'title' => __('admin.dashboard_task_login_url_title'),
                'description' => __('admin.dashboard_task_login_url_description', ['path' => AdminLoginPath::url()]),
                'completed' => $adminLoginPathCustomized,
                'href' => route('admin.settings.index', ['tab' => 'admin']),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $adminLoginPathCustomized ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'mail_enabled',
                'title' => __('admin.dashboard_task_email_title'),
                'description' => __('admin.dashboard_task_email_description'),
                'completed' => $mailConfigured,
                'href' => route('admin.email.index'),
                'action' => __('admin.dashboard_task_cta_configure'),
                'status_label' => $mailConfigured ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
            [
                'key' => 'http2',
                'title' => __('admin.dashboard_task_http2_title'),
                'description' => __('admin.dashboard_task_http2_description', ['protocol' => $serverProtocol !== '' ? $serverProtocol : 'unknown']),
                'completed' => $http2Enabled,
                'href' => null,
                'action' => null,
                'status_label' => $http2Enabled ? __('admin.dashboard_task_done') : __('admin.dashboard_task_pending'),
            ],
        ];
    }
}
