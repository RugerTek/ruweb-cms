<?php

use App\Http\Controllers\SupportAccessController;
use Illuminate\Support\Facades\Route;

/*
 * «Acceso de soporte RügerTek» (docs/operations/support-access.md), required from routes/web.php before the page
 * catch-all. Outside the admin group: the visitor has no session yet, the signed pass is the credential. Always
 * registered (route:cache); without RUWEB_SUPPORT_PUBLIC_KEYS the controller answers 404.
 */
Route::get('/_ruweb/support/enter', [SupportAccessController::class, 'enter'])
    ->middleware('throttle:10,1')
    ->name('support.enter');
