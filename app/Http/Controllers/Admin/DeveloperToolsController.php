<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotFoundHit;
use App\Models\Redirect as RedirectRule;
use App\Models\Setting;
use App\Services\Snapshots\EnvironmentReportService;
use App\Services\Snapshots\SnapshotException;
use App\Services\Snapshots\SnapshotHousekeeping;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeveloperToolsController extends Controller
{
    public function __construct(
        protected EnvironmentReportService $environment,
        protected SnapshotService $snapshots,
        protected SnapshotHousekeeping $housekeeping
    ) {}

    /** Tabs of the screen; anything else falls back to the first one. */
    private const TABS = ['system', 'migration', 'redirects', 'not-found'];

    public function index(Request $request): View
    {
        $activeTab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'system';
        $perPage = (int) config('cms.redirects.per_page', 25);

        return view('admin.developer-tools.index', [
            'report' => $this->environment->report(),
            'activeTab' => $activeTab,
            'pending' => $activeTab === 'migration' ? $this->housekeeping->pendingList() : [],
            'usage' => $activeTab === 'migration' ? $this->housekeeping->usage() : [],
            'redirects' => $activeTab === 'redirects'
                ? RedirectRule::query()->orderByDesc('hit_count')->orderByDesc('id')->paginate($perPage, ['*'], 'redirects')->withQueryString()
                : null,
            'notFoundHits' => $activeTab === 'not-found'
                ? NotFoundHit::query()->orderByDesc('last_seen_at')->paginate($perPage, ['*'], 'not_found')->withQueryString()
                : null,
            'prefillSource' => $request->string('source')->toString(),
        ]);
    }

    public function download(): BinaryFileResponse|RedirectResponse
    {
        try {
            $siteName = Setting::get('site_name') ?: config('app.name');
            $archiveName = Str::slug($siteName).'-'.config('cms.version').'-'.now()->format('Ymd-His');
            $path = $this->snapshots->create($archiveName, SnapshotService::downloadsDirectory());
        } catch (SnapshotException $exception) {
            return $this->backToMigration()->with('error', $exception->getMessage());
        }

        return response()
            ->download($path, basename($path), ['Content-Type' => 'application/octet-stream'])
            ->deleteFileAfterSend(true);
    }

    /**
     * Step 1 of the restore flow: store the archive, inspect it and send the
     * operator to the review screen. Nothing is restored here.
     */
    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'backup' => ['required', 'file', 'max:512000'],
        ]);

        $file = $request->file('backup');

        if (! $file || $file->getClientOriginalExtension() !== 'appbackup') {
            return $this->backToMigration()->with('error', __('admin.snapshot_invalid_extension'));
        }

        $directory = SnapshotService::pendingUploadsDirectory();
        File::ensureDirectoryExists($directory);
        $path = $file->move($directory, uniqid('incoming-', true).'.tmp')->getPathname();

        try {
            $report = $this->snapshots->inspect($path);
            $token = $this->housekeeping->storePending($path, $report, $file->getClientOriginalName());
        } catch (SnapshotException $exception) {
            File::delete($path);

            return $this->backToMigration()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.developer-tools.review', ['token' => $token]);
    }

    public function review(string $token): View|RedirectResponse
    {
        $pending = $this->findPending($token);

        if ($pending === null) {
            return $this->backToMigration()->with('error', __('admin.snapshot_pending_missing'));
        }

        return view('admin.developer-tools.review', $pending);
    }

    /**
     * Step 2: restore a pending archive with the accepted risk keys.
     */
    public function restore(Request $request, string $token): RedirectResponse
    {
        $pending = $this->findPending($token);

        if ($pending === null) {
            return $this->backToMigration()->with('error', __('admin.snapshot_pending_missing'));
        }

        $accepted = array_values(array_filter(array_map('strval', (array) $request->input('accepted', []))));

        try {
            $result = $this->snapshots->restore($this->housekeeping->pendingArchivePath($token), $accepted);
        } catch (SnapshotException $exception) {
            return redirect()
                ->route('admin.developer-tools.review', ['token' => $token])
                ->with('error', $exception->getMessage());
        }

        $this->housekeeping->discardPending($token);

        return $this->backToMigration()->with('success', __('admin.snapshot_restored', [
            'tables' => $result['tables'],
            'files' => $result['uploads_files'],
        ]));
    }

    public function discard(string $token): RedirectResponse
    {
        try {
            $this->housekeeping->discardPending($token);
        } catch (SnapshotException) {
            return $this->backToMigration()->with('error', __('admin.snapshot_pending_missing'));
        }

        return $this->backToMigration()->with('success', __('admin.snapshot_pending_discarded'));
    }

    public function prune(): RedirectResponse
    {
        $removed = $this->housekeeping->prune();
        $files = array_sum(array_column($removed, 'files'));
        $bytes = array_sum(array_column($removed, 'bytes'));

        return $this->backToMigration()->with('success', __('admin.snapshot_pruned', [
            'files' => $files,
            'size' => $this->formatBytes($bytes),
        ]));
    }

    protected function findPending(string $token): ?array
    {
        try {
            return $this->housekeeping->pending($token);
        } catch (SnapshotException) {
            return null;
        }
    }

    protected function backToMigration(): RedirectResponse
    {
        return redirect()->route('admin.developer-tools.index', ['tab' => 'migration']);
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = max(0, $bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
