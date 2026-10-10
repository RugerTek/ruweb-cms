<?php

namespace App\Services\Forms;

use Illuminate\Support\Facades\Crypt;

class FormToken
{
    public const MAX_SECONDS = 7200;

    public function issue(int $pageId, string $form, string $locale): string
    {
        return Crypt::encryptString(json_encode(['page' => $pageId, 'form' => $form, 'locale' => $locale, 'issued' => now()->timestamp], JSON_THROW_ON_ERROR));
    }

    public function age(mixed $token, int $pageId, string $form, string $locale): ?int
    {
        if (! is_string($token) || strlen($token) > 4096) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || ($data['page'] ?? null) !== $pageId || ($data['form'] ?? null) !== $form || ($data['locale'] ?? null) !== $locale || ! is_int($data['issued'] ?? null)) {
            return null;
        }
        $age = now()->timestamp - $data['issued'];

        return $age >= 0 && $age <= self::MAX_SECONDS ? $age : null;
    }
}
