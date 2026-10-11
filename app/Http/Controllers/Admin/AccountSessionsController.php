<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OauthAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountSessionsController extends Controller
{
    public function destroy(Request $request, string $session)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_if($session === hash('sha256', $request->session()->getId()), 422);
        $affected = DB::table('admin_sessions')->where('user_id', $request->user()->id)
            ->where('id', $session)->update(['revoked_at' => now()]);
        abort_unless($affected, 404);
        $request->user()->forceFill(['remember_token' => Str::random(60)])->save();

        return redirect()->route('admin.profile.index', ['tab' => 'security'])->with('success', __('security.session_revoked'));
    }

    public function destroyOthers(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        DB::table('admin_sessions')->where('user_id', $request->user()->id)
            ->where('id', '!=', hash('sha256', $request->session()->getId()))->update(['revoked_at' => now()]);
        $request->user()->increment('security_version');
        $request->user()->forceFill(['remember_token' => Str::random(60)])->save();
        $request->session()->put('security_version', $request->user()->fresh()->security_version);

        return redirect()->route('admin.profile.index', ['tab' => 'security'])->with('success', __('security.session_revoked'));
    }

    public function revokeConnection(Request $request, OauthAccessToken $connection)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_unless($connection->user_id === $request->user()->id, 403);
        $connection->update(['revoked_at' => now()]);

        return redirect()->route('admin.profile.index', ['tab' => 'security'])->with('success', __('security.connection_revoked'));
    }
}
