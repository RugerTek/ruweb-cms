<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** RFC 6238, SHA-1, 6 digits and 30-second steps. */
class Totp
{
    public function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $alphabet[bindec($chunk)];
        }

        return $result;
    }

    public function code(string $secret, int $counter): string
    {
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter & 0xFFFFFFFF), $binary, true);
        $offset = ord($hash[19]) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function verify(User $user, string $code, bool $allowRecovery = true): bool
    {
        return DB::transaction(function () use ($user, $code, $allowRecovery) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $locked->two_factor_secret) {
                return false;
            }
            if (preg_match('/^\d{6}$/D', $code)) {
                $counter = intdiv(now()->timestamp, 30);
                for ($candidate = $counter - 1; $candidate <= $counter + 1; $candidate++) {
                    if ($candidate > ($locked->two_factor_last_counter ?? -1)
                        && hash_equals($this->code($locked->two_factor_secret, $candidate), $code)) {
                        $query = User::whereKey($locked->id);
                        $query->where(fn ($q) => $q->whereNull('two_factor_last_counter')->orWhere('two_factor_last_counter', '<', $candidate));

                        return $query->update(['two_factor_last_counter' => $candidate]) === 1;
                    }
                }
            }
            if ($allowRecovery) {
                $codes = $locked->two_factor_recovery_codes ?? [];
                foreach ($codes as $index => $hash) {
                    if (Hash::check($code, $hash)) {
                        unset($codes[$index]);
                        $locked->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                        return true;
                    }
                }
            }

            return false;
        }, 3);
    }

    public function recoveryCodes(User $user): array
    {
        $codes = array_map(fn () => bin2hex(random_bytes(6)), range(1, 8));
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($code) => Hash::make($code), $codes)])->save();

        return $codes;
    }
}
