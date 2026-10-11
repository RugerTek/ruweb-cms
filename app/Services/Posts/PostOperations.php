<?php

namespace App\Services\Posts;

use App\Mcp\ContentPatch;
use App\Models\Locale;
use App\Models\Post;
use App\Models\User;
use App\Services\Content\ContentDeletion;
use App\Services\Content\RevisionGuard;
use App\Services\Content\TranslationOperations;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Post writes shared by the panel and bearer transports. */
final class PostOperations
{
    /** $patch edits in place like the Live Editor; see PageOperations::save(). */
    public function save(User $actor, array $input, ?Post $post = null, bool $draftOnly = false, ?string $base = null, bool $patch = false): Post
    {
        Gate::forUser($actor)->authorize($post ? 'update' : 'create', $post ?? Post::class);
        $patch = $patch && $post !== null;

        return $this->transaction($actor, function () use ($actor, $input, $post, $draftOnly, $base, $patch) {
            $post = $post ? Post::query()->lockForUpdate()->findOrFail($post->id) : null;
            if ($draftOnly && $post && $post->status !== 'draft') {
                throw new AuthorizationException(__('editorial.mcp_draft_required'));
            }
            if ($patch && $post->status !== 'draft' && ! $actor->isAdmin()) {
                throw new AuthorizationException(__('editorial.mcp_live_edit_admin_only'));
            }
            if ($draftOnly && ! $post) {
                $input['status'] = 'draft';
            }
            $data = (new PostInput($input, $post, ($draftOnly || $patch) && $post !== null, $draftOnly))->validate();
            $this->checkRevision($post, $data['expected_revision'] ?? $base);
            unset($data['expected_revision']);
            $history = app(EditorialHistory::class);
            if (! $draftOnly && ! $patch && $post && $actor->isEditor() && $post->status === 'published') {
                $history->stage($post, $data);

                return $post;
            }
            $data['status'] = $draftOnly ? 'draft' : ($patch ? $post->status : $data['status']);
            if ($data['status'] === 'published' && ! $actor->isAdmin()) {
                throw new AuthorizationException(__('editorial.publish_forbidden'));
            }
            $data['locale'] = $data['locale'] ?? $post?->locale ?? Locale::baseCode();
            $data['updated_by'] = $actor->id;
            if ($data['status'] === 'published' && empty($data['published_at'])) {
                $data['published_at'] = $post?->published_at ?? now();
            }
            $featured = $data['featured_image'] ?? null;
            $categories = $data['categories'] ?? [];
            $replaceFeatured = (! $draftOnly && ! $patch) || array_key_exists('featured_image', $data);
            $replaceCategories = (! $draftOnly && ! $patch) || array_key_exists('categories', $data);
            unset($data['featured_image'], $data['categories'], $data['acknowledged_fields']);
            if (($draftOnly || $patch) && $post && isset($data['meta_json'])) {
                $data['meta_json'] = ContentPatch::apply($post->meta_json ?? [], $data['meta_json']);
            }
            if ($post) {
                $post->update($data);
            } else {
                $post = Post::create($data + ['created_by' => $actor->id]);
            }
            if ($post->isBaseLocale()) {
                $post->source_fingerprint = $post->translatableFingerprint();
                $post->source_field_hashes = $post->translatableFieldFingerprints();
            } elseif (! $draftOnly && ! $patch) {
                $post->syncTranslationFromBase();
            }
            $post->save();
            if ($replaceFeatured) {
                $post->media()->wherePivot('collection', 'featured_image')->detach();
                if ($featured) {
                    $post->attachMedia($featured, 'featured_image');
                }
            }
            if ($replaceCategories) {
                $post->syncTaxonomies($categories, 'category');
            }
            $history->capture($post->fresh());

            return $post->fresh();
        });
    }

    public function publish(User $actor, Post $post, ?string $expectedRevision = null): Post
    {
        Gate::forUser($actor)->authorize('update', $post);
        if (! $actor->isAdmin()) {
            throw new AuthorizationException(__('editorial.publish_forbidden'));
        }

        return $this->transaction($actor, function () use ($actor, $post, $expectedRevision) {
            $post = Post::query()->lockForUpdate()->findOrFail($post->id);
            $this->checkRevision($post, $expectedRevision);
            $post->update(['status' => 'published', 'published_at' => $post->published_at ?? now(), 'updated_by' => $actor->id]);

            return $post->fresh();
        });
    }

    /** Returns what the deletion left behind; see ContentDeletion. */
    public function delete(User $actor, Post $post, ?string $expectedRevision = null): array
    {
        Gate::forUser($actor)->authorize('delete', $post);

        return $this->transaction($actor, function () use ($post, $expectedRevision) {
            $post = Post::query()->lockForUpdate()->findOrFail($post->id);
            $this->checkRevision($post, $expectedRevision);
            $warnings = app(ContentDeletion::class)->cleanUp($post);
            app(EditorialHistory::class)->capture($post);
            $post->media()->detach();
            $post->taxonomies()->detach();
            $post->delete();

            return $warnings;
        });
    }

    public function schedule(User $actor, Post $post, string $publishedAt, ?string $expectedRevision = null): Post
    {
        return $this->changePublication($actor, $post, ['status' => 'published', 'published_at' => $publishedAt], $expectedRevision);
    }

    public function setExpiry(User $actor, Post $post, ?string $expiresAt, ?string $expectedRevision = null): Post
    {
        Gate::forUser($actor)->authorize('update', $post);
        if (! $actor->isAdmin()) {
            throw new AuthorizationException(__('editorial.publish_forbidden'));
        }

        return $this->transaction($actor, function () use ($actor, $post, $expiresAt, $expectedRevision) {
            $post = Post::query()->lockForUpdate()->findOrFail($post->id);
            $this->checkRevision($post, $expectedRevision);
            Validator::make(['expires_at' => $expiresAt, 'published_at' => $post->published_at], ['expires_at' => ['nullable', 'date', 'after:now', 'after:published_at']])->validate();
            $post->update(['expires_at' => $expiresAt, 'updated_by' => $actor->id]);

            return $post->fresh();
        });
    }

    public function unpublish(User $actor, Post $post, ?string $expectedRevision = null): Post
    {
        return $this->changePublication($actor, $post, ['status' => 'draft'], $expectedRevision);
    }

    private function changePublication(User $actor, Post $post, array $data, ?string $expectedRevision): Post
    {
        Gate::forUser($actor)->authorize('update', $post);
        if (! $actor->isAdmin()) {
            throw new AuthorizationException(__('editorial.publish_forbidden'));
        }

        return $this->transaction($actor, function () use ($actor, $post, $data, $expectedRevision) {
            $post = Post::query()->lockForUpdate()->findOrFail($post->id);
            $this->checkRevision($post, $expectedRevision);
            if (isset($data['published_at'])) {
                Validator::make($data + ['expires_at' => $post->expires_at], [
                    'published_at' => ['required', 'date', 'after:now'],
                    'expires_at' => ['nullable', 'date', 'after:published_at'],
                ])->validate();
            }
            $post->update($data + ['updated_by' => $actor->id]);

            return $post->fresh();
        });
    }

    public function translate(User $actor, Post $post, string $locale): Post
    {
        return app(TranslationOperations::class)->create($actor, $post, $locale);
    }

    private function checkRevision(?Post $post, ?string $revision): void
    {
        if ($post) {
            RevisionGuard::check($post, $revision);
        }
    }

    private function transaction(User $actor, \Closure $callback): mixed
    {
        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction($callback, 3));
    }
}
