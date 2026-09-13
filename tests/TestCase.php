<?php

namespace Tests;

use App\Models\Setting;
use App\Support\AdminLoginPath;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

abstract class TestCase extends BaseTestCase
{
    /** Isolated snapshot root for this test, never the developer's real storage. */
    protected ?string $snapshotsRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::clearCache();
        AdminLoginPath::clearCache();

        $this->snapshotsRoot = sys_get_temp_dir().'/ruweb-cms-tests/'.uniqid('snapshots-', true);
        config(['cms.snapshots.root' => $this->snapshotsRoot]);
    }

    protected function tearDown(): void
    {
        if ($this->snapshotsRoot !== null && is_dir($this->snapshotsRoot)) {
            File::deleteDirectory($this->snapshotsRoot);
        }

        parent::tearDown();
    }

    /**
     * Re-evaluate the HTTP route files so the login route reflects the current
     * admin_login_path. Production re-runs route files on every request; the
     * test process boots once, so a mid-test path change needs an explicit
     * rebuild. Mirrors the route loading in bootstrap/app.php.
     */
    protected function rebuildRoutes(): void
    {
        AdminLoginPath::clearCache();

        $router = $this->app['router'];
        $router->setRoutes(new RouteCollection);

        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::middleware('web')->group(base_path('routes/admin.php'));
        Route::middleware('web')->group(base_path('routes/oauth.php'));
        Route::prefix('mcp')->middleware('mcp.auth')->group(base_path('routes/mcp.php'));

        // Names are assigned fluently after the route is added, so the
        // collection's name lookup must be rebuilt for route()/getByName().
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
