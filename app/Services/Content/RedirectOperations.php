<?php

namespace App\Services\Content;

use App\Exceptions\RevisionConflict;
use App\Models\NotFoundHit;
use App\Models\Redirect as RedirectRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Redirect rules and the 404 monitor, shared by Developer Tools and both bearer transports. Admin only. */
final class RedirectOperations
{
    /** Traffic counters are excluded so visits never invalidate an agent's read. */
    public function revision(RedirectRule $rule): string
    {
        return hash('sha256', json_encode([$rule->source_path, $rule->target_url, (int) $rule->status_code, (bool) $rule->is_active]));
    }

    /**
     * Creates, or replaces the rule for the same source path, like the panel form. $partial keeps omitted fields of
     * an existing rule (bearer patches); the panel form always sends the whole rule.
     */
    public function save(User $actor, array $input, ?RedirectRule $rule = null, ?string $expectedRevision = null, bool $partial = false): RedirectRule
    {
        $this->authorize($actor);
        if ($partial && $rule) {
            $input += ['source_path' => $rule->source_path, 'target_url' => $rule->target_url, 'status_code' => $rule->status_code, 'is_active' => $rule->is_active];
        }
        $data = $this->validated($input);
        if ($reason = $this->rejectionReason($data['source_path'], $data['target_url'], $rule?->id)) {
            throw ValidationException::withMessages(['source_path' => $reason]);
        }

        return DB::transaction(function () use ($data, $rule, $expectedRevision) {
            if ($rule) {
                $rule = RedirectRule::query()->lockForUpdate()->findOrFail($rule->id);
                $this->check($rule, $expectedRevision);
                $rule->update($data);

                return $rule->fresh();
            }

            return RedirectRule::updateOrCreate(['source_path' => $data['source_path']], $data)->fresh();
        });
    }

    public function delete(User $actor, RedirectRule $rule, ?string $expectedRevision = null): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($rule, $expectedRevision) {
            $rule = RedirectRule::query()->lockForUpdate()->findOrFail($rule->id);
            $this->check($rule, $expectedRevision);
            $rule->delete();
        });
    }

    /** Forget one recorded 404, or all of them when $hit is null. Returns how many were removed. */
    public function forgetNotFound(User $actor, ?NotFoundHit $hit = null): int
    {
        $this->authorize($actor);

        return $hit ? (int) $hit->delete() : NotFoundHit::query()->delete();
    }

    /**
     * Why a rule cannot be saved, or null. Rejects the homepage as source, non-HTTP(S) schemes, and the two ways a rule
     * loops: pointing at itself, and pointing at a path that points back.
     */
    public function rejectionReason(string $sourcePath, string $targetUrl, ?int $ignoreId = null): ?string
    {
        if ($sourcePath === '/') {
            return __('admin.redirect_error_home_source');
        }
        if ($targetUrl === '') {
            return __('admin.redirect_error_empty_target');
        }
        $scheme = parse_url($targetUrl, PHP_URL_SCHEME);
        if (is_string($scheme) && ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return __('admin.redirect_error_unsafe_target');
        }
        if (parse_url($targetUrl, PHP_URL_HOST)) {
            return null;
        }
        $targetPath = RedirectRule::normalizePath($targetUrl);
        if ($sourcePath === $targetPath) {
            return __('admin.redirect_error_same');
        }
        $loops = RedirectRule::query()->active()->where('source_path', $targetPath)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->contains(fn (RedirectRule $rule): bool => ! $rule->isExternal() && RedirectRule::normalizePath($rule->target_url) === $sourcePath);

        return $loops ? __('admin.redirect_error_loop', ['path' => $targetPath]) : null;
    }

    /** @return array{source_path: string, target_url: string, status_code: int, is_active: bool} */
    private function validated(array $input): array
    {
        $data = Validator::make($input, [
            'source_path' => ['required', 'string', 'max:255'],
            'target_url' => ['required', 'string', 'max:255'],
            'status_code' => ['required', Rule::in(config('cms.redirects.status_codes'))],
            'is_active' => ['nullable', 'boolean'],
        ])->validate();

        return [
            'source_path' => RedirectRule::normalizePath($data['source_path']),
            'target_url' => trim($data['target_url']),
            'status_code' => (int) $data['status_code'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    private function check(RedirectRule $rule, ?string $expected): void
    {
        if ($expected !== null && ! hash_equals($current = $this->revision($rule), $expected)) {
            throw new RevisionConflict($expected, $current);
        }
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException('Only administrators manage redirects.');
        }
    }
}
