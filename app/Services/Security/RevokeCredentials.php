<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RevokeCredentials
{
    public function all(User $user, ?string $keepSession = null): void
    {
        DB::transaction(function () use ($user, $keepSession) {
            $user->mcpApiKeys()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->forceFill(['mcp_api_key_hash' => null, 'mcp_api_key_generated_at' => null,
                'mcp_api_key_last_used_at' => null, 'pending_email' => null,
                'pending_email_token' => null, 'pending_email_expires_at' => null, 'remember_token' => Str::random(60)])->save();
            $user->increment('security_version');
            $sessions = DB::table('admin_sessions')->where('user_id', $user->id);
            if ($keepSession) {
                $sessions->where('id', '!=', hash('sha256', $keepSession));
            }
            $sessions->update(['revoked_at' => now()]);
            if (Schema::hasTable('sessions')) {
                $query = DB::table('sessions')->where('user_id', $user->id);
                if ($keepSession) {
                    $query->where('id', '!=', $keepSession);
                }
                $query->delete();
            }
        }, 3);
    }
}
