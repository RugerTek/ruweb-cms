<?php

namespace App\Services\Pages;

use App\Mcp\ContentPatch;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Content\ContentDeletion;
use App\Services\Content\RevisionGuard;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Page writes shared by the panel and both bearer transports. */
final class PageOperations
{
    /**
     * $patch edits an existing page in place like the Live Editor: partial input, ContentPatch semantics and the
     * current publication state kept. Published pages change live only for administrators.
     */
    public function save(User $actor, array $input, ?Page $page = null, bool $draftOnly = false, ?string $base = null, bool $patch = false): Page
    {
        $this->authorize($actor);
        $base ??= $input['expected_revision'] ?? null;
        $patch = $patch && $page !== null;

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $input, $page, $draftOnly, $base, $patch) {
            $page = $page ? Page::query()->lockForUpdate()->findOrFail($page->id) : null;
            if ($draftOnly && $page && $page->status !== 'draft') {
                throw new AuthorizationException(__('editorial.mcp_draft_required'));
            }
            if ($patch && $page->status !== 'draft' && ! $actor->isAdmin()) {
                throw new AuthorizationException(__('editorial.mcp_live_edit_admin_only'));
            }
            $history = app(EditorialHistory::class);
            if ($page) {
                RevisionGuard::check($page, $base);
            }
            if ($draftOnly && ! $page) {
                $input['status'] = 'draft';
            }
            $data = (new PageInput($input, $page, ($draftOnly || $patch) && $page !== null, $draftOnly || $patch))->validate();
            if (! $draftOnly && ! $patch && $page && $actor->isEditor() && $page->status === 'published') {
                $history->stage($page, $data);

                return $page;
            }
            $data['status'] = $draftOnly ? 'draft' : ($patch ? $page->status : $data['status']);
            if ($data['status'] === 'published' && ! $actor->isAdmin()) {
                throw new AuthorizationException(__('editorial.publish_forbidden'));
            }
            $data['locale'] = $data['locale'] ?? $page?->locale ?? Locale::baseCode();
            $data['updated_by'] = $actor->id;
            if ($data['status'] === 'published' && empty($data['published_at'])) {
                $data['published_at'] = $page?->published_at ?? now();
            }
            $featured = $data['featured_image'] ?? null;
            $replaceFeatured = (! $draftOnly && ! $patch) || array_key_exists('featured_image', $data);
            unset($data['featured_image'], $data['acknowledged_fields'], $data['editor_field_origins']);
            if ($page && isset($data['content_json'])) {
                $existing = $page->content_json ?? [];
                if ($draftOnly || $patch) {
                    $data['content_json'] = ContentPatch::apply($existing, $data['content_json']);
                } else {
                    $submitted = $data['content_json'];
                    foreach ($submitted['sections'] ?? [] as $key => $section) {
                        $submitted['sections'][$key] = array_replace($existing['sections'][$key] ?? [], $section);
                    }
                    if (isset($submitted['meta'])) {
                        $submitted['meta'] = array_replace($existing['meta'] ?? [], $submitted['meta']);
                    }
                    $submitted['sections'] = array_replace($existing['sections'] ?? [], $submitted['sections'] ?? []);
                    $data['content_json'] = array_replace($existing, $submitted);
                }
            }
            if ($page) {
                $page->update($data);
            } else {
                $page = Page::create($data + ['created_by' => $actor->id]);
            }
            if ($page->isBaseLocale()) {
                $page->source_fingerprint = $page->translatableFingerprint();
                $page->source_field_hashes = $page->translatableFieldFingerprints();
            } elseif (! $draftOnly && ! $patch) {
                $page->syncTranslationFromBase();
            }
            $page->save();
            if ($replaceFeatured) {
                $page->media()->wherePivot('collection', 'featured_image')->detach();
                if ($featured) {
                    $page->attachMedia($featured, 'featured_image');
                }
            }
            $history->capture($page->fresh());

            return $page->fresh();
        }, 3));
    }

    public function publish(User $actor, Page $page, ?string $expectedRevision = null): Page
    {
        $this->authorize($actor);
        if (! $actor->isAdmin()) {
            throw new AuthorizationException(__('editorial.publish_forbidden'));
        }

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $page, $expectedRevision) {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            RevisionGuard::check($page, $expectedRevision);
            $page->update(['status' => 'published', 'published_at' => $page->published_at ?? now(), 'updated_by' => $actor->id]);

            app(EditorialHistory::class)->capture($page->fresh());

            return $page->fresh();
        }, 3));
    }

    public function setPublication(User $actor, Page $page, ?string $date, ?string $expectedRevision = null): Page
    {
        $this->authorize($actor);
        if (! $actor->isAdmin()) {
            throw new AuthorizationException(__('editorial.publish_forbidden'));
        }
        if ($date !== null) {
            Validator::make(['published_at' => $date], ['published_at' => ['required', 'date', 'after:now']])->validate();
        }

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $page, $date, $expectedRevision) {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            RevisionGuard::check($page, $expectedRevision);
            if ($date !== null && $page->expires_at !== null) {
                Validator::make(['published_at' => $date, 'expires_at' => $page->expires_at], ['expires_at' => ['date', 'after:published_at']])->validate();
            }
            $page->update(['status' => $date === null ? 'draft' : 'published', 'published_at' => $date === null ? $page->published_at : $date, 'updated_by' => $actor->id]);
            app(EditorialHistory::class)->capture($page->fresh());

            return $page->fresh();
        }, 3));
    }

    public function setExpiry(User $actor, Page $page, ?string $expiresAt, ?string $expectedRevision = null): Page
    {
        $this->authorize($actor);
        if (! $actor->isAdmin()) {
            throw new AuthorizationException(__('editorial.publish_forbidden'));
        }

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $page, $expiresAt, $expectedRevision) {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            RevisionGuard::check($page, $expectedRevision);
            Validator::make(['expires_at' => $expiresAt, 'published_at' => $page->published_at], ['expires_at' => ['nullable', 'date', 'after:now', 'after:published_at']])->validate();
            $page->update(['expires_at' => $expiresAt, 'updated_by' => $actor->id]);
            app(EditorialHistory::class)->capture($page->fresh());

            return $page->fresh();
        }, 3));
    }

    /**
     * Delete for the panel and both transports. The base-language homepage is protected; menu links are cleaned
     * up and surviving translations reported (see ContentDeletion). Returns those warnings.
     */
    public function delete(User $actor, Page $page, ?string $expectedRevision = null): array
    {
        $this->authorize($actor);

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $page, $expectedRevision) {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            RevisionGuard::check($page, $expectedRevision);
            if ($actor->isEditor() && $page->status === 'published') {
                throw new AuthorizationException(__('editorial.publish_forbidden'));
            }
            if ($page->isBaseLocale() && $page->translation_group_id && $page->translation_group_id === Setting::get('homepage_translation_group_id')) {
                throw ValidationException::withMessages(['page_id' => __('editorial.homepage_delete_forbidden')]);
            }
            $warnings = app(ContentDeletion::class)->cleanUp($page);
            app(EditorialHistory::class)->capture($page);
            $page->media()->detach();
            $page->delete();

            return $warnings;
        }, 3));
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->role, config('cms.roles', []), true)) {
            throw new AuthorizationException('Unauthorized role for page operation.');
        }
    }
}
