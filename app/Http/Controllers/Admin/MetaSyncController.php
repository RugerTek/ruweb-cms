<?php

namespace App\Http\Controllers\Admin;

use App\Connectors\Meta\MetaCatalogConnector;
use App\Http\Controllers\Controller;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Connectors\Meta\MetaCatalogSyncException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Sincronizar ahora» of the Meta catalog connected by API (plan-meta-v2, track 2c; route
 * `admin.connectors.meta.sync`, throttled): thin over MetaCatalogSync::syncNow(). The state of the sync is the
 * resources/views/admin/connectors/_meta-sync.blade.php section of the Meta detail.
 */
class MetaSyncController extends Controller
{
    public function sync(Request $request, MetaCatalogSync $sync): RedirectResponse
    {
        $back = redirect()->route('admin.connectors.show', ['connector' => MetaCatalogConnector::KEY])->withFragment('meta-sync');
        try {
            $sync->syncNow($request->user());
        } catch (MetaCatalogSyncException $e) {
            return $back->withErrors(['meta_sync' => $e->getMessage()]);
        }

        return $back->with('success', __('meta_sync.sync_requested'));
    }
}
