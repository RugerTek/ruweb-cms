<?php

namespace App\Services\Editorial;

use App\Mcp\ContentPatch;
use App\Mcp\ContentResponse;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Content\ContentEditLock;
use App\Services\Content\RevisionGuard;
use App\Services\Pages\PageInput;
use App\Services\Posts\PostInput;
use App\Services\Revisions\ContentRevisionLedger;
use App\Support\SiteUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Shared editorial operations; transport permissions are an additional boundary. */
final class EditorialOperations
{
    public function __construct(private EditorialHistory $history) {}

    public function read(User $actor, string $type, int $id, string $action, array $input = []): array
    {
        Validator::make($input, [
            'version_id' => [in_array($action, ['version', 'diff'], true) ? 'required' : 'sometimes', 'integer', 'min:1'],
            'compare_version_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'review_id' => ['sometimes', 'integer', 'min:1'], 'comment_id' => ['sometimes', 'integer', 'min:1'],
            'comments_page' => ['sometimes', 'integer', 'min:1'], 'field' => ['sometimes', 'string', 'max:500'],
            'offset' => ['sometimes', 'integer', 'min:0'], 'length' => ['sometimes', 'integer', 'min:1', 'max:8192'],
        ])->validate();
        $model = $this->history->model($type, $id);
        $this->authorize($actor, $model);
        $revision = $this->history->fingerprint($model);

        return match ($action) {
            'versions' => $this->listVersions($model, $input) + ['revision' => $revision],
            'version' => $this->readVersion($model, $input) + ['revision' => $revision],
            'diff' => $this->difference($model, $input) + ['revision' => $revision],
            'reviews' => $this->listReviews($model, $input) + ['revision' => $revision],
            default => abort(422, 'Unknown editorial read operation.'),
        };
    }

    /** MCP mutations require a revision and durable, actor-scoped operation key. */
    public function write(User $actor, string $type, int $id, string $action, array $input): array
    {
        Validator::make($input, [
            'expected_revision' => [$action === 'preview' ? 'nullable' : 'required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'operation_key' => [$action === 'preview' ? 'nullable' : 'required', 'string', 'max:100'],
        ])->validate();
        if ($action === 'propose') {
            Validator::make($input, ['changes' => ['required', 'array']])->validate();
        }
        if ($action === 'restore') {
            Validator::make($input, ['mode' => ['required', Rule::in(['draft', 'proposal'])]])->validate();
        }
        if ($action === 'review_request') {
            Validator::make($input, ['version_id' => ['required', 'integer']])->validate();
        }
        $model = $this->history->model($type, $id);
        $this->authorize($actor, $model, true);

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $model, $action, $input) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            $requestHash = hash('sha256', json_encode($this->canonical([$action, $input]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $key = $input['operation_key'] ?? null;
            if ($key && $action !== 'preview') {
                $receipt = DB::table('editorial_operation_receipts')->where(['user_id' => $actor->id, 'content_type' => $model->getTable(), 'content_id' => $model->id, 'operation_key' => $key])->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->request_hash, $requestHash), 409, 'Operation key already used with different input. Retry the original input or choose a new key.');

                    return json_decode($receipt->result, true);
                }
            }
            if (in_array($action, ['propose', 'restore', 'review_decide'], true)) {
                abort_if(app(ContentEditLock::class)->active($model), 423, __('admin.editor_lock_lost'));
            }
            RevisionGuard::check($model, $input['expected_revision'] ?? null);
            $result = match ($action) {
                'propose' => ['version' => $this->proposeLocked($actor, $model, $input['changes'] ?? [])],
                'review_request' => ['review' => $this->requestLocked($actor, $model, $input)],
                'review_decide' => ['review' => $this->decideLocked($actor, $model, $input)],
                'review_comment' => ['comment' => $this->commentLocked($actor, $model, $input)],
                'restore' => $this->restoreLocked($actor, $model, $input),
                'preview' => $this->previewLocked($actor, $model, $input),
                'preview_revoke' => ['link' => $this->revokeLocked($actor, $model, $input)],
                default => abort(422, 'Unknown editorial write operation.'),
            };
            $result = ContentResponse::bounded($result);
            if (isset($result['recovery'])) {
                $result['recovery'] = 'Use content_reviews with review_id and comment_id (or field=comment) to recover long notes in ranges.';
            }
            $result['revision'] = $this->history->fingerprint($model->fresh());
            if ($key && $action !== 'preview') {
                DB::table('editorial_operation_receipts')->insert(['user_id' => $actor->id, 'content_type' => $model->getTable(), 'content_id' => $model->id, 'operation_key' => $key, 'request_hash' => $requestHash, 'result' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $result;
        }, 3));
    }

    /** Browser adapters retain optional revision compatibility without receipts. */
    public function perform(User $actor, Model $model, string $action, array $input = []): array
    {
        $this->authorize($actor, $model, true);

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $model, $action, $input) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            RevisionGuard::check($model, $input['expected_revision'] ?? null);
            $result = match ($action) {
                'propose' => ['version' => $this->proposeLocked($actor, $model, $input['changes'] ?? [])],
                'review_request' => ['review' => $this->requestLocked($actor, $model, $input)],
                'review_decide' => ['review' => $this->decideLocked($actor, $model, $input)],
                'review_comment' => ['comment' => $this->commentLocked($actor, $model, $input)],
                'restore' => $this->restoreLocked($actor, $model, $input),
                'preview' => $this->previewLocked($actor, $model, $input),
                'preview_revoke' => ['link' => $this->revokeLocked($actor, $model, $input)],
                default => abort(422, 'Unknown editorial operation.'),
            };

            return $result + ['revision' => $this->history->fingerprint($model->fresh())];
        }, 3));
    }

    public function restore(User $actor, Model $model, int $version, ?string $expectedRevision = null): Model
    {
        $this->perform($actor, $model, 'restore', ['version_id' => $version, 'expected_revision' => $expectedRevision]);

        return $model->fresh();
    }

    public function requestReview(User $actor, Model $model, int $reviewerId, ?int $versionId = null, ?string $comment = null, ?string $expectedRevision = null): object
    {
        return (object) $this->perform($actor, $model, 'review_request', ['reviewer_id' => $reviewerId, 'version_id' => $versionId, 'comment' => $comment, 'expected_revision' => $expectedRevision])['review'];
    }

    public function decide(User $actor, Model $model, int $review, string $state, ?string $comment = null, ?string $expectedRevision = null): object
    {
        return (object) $this->perform($actor, $model, 'review_decide', ['review_id' => $review, 'state' => $state, 'comment' => $comment, 'expected_revision' => $expectedRevision])['review'];
    }

    public function comment(User $actor, Model $model, int $review, string $body): object
    {
        return (object) $this->perform($actor, $model, 'review_comment', ['review_id' => $review, 'body' => $body])['comment'];
    }

    public function share(User $actor, Model $model, int $hours = 1, ?int $version = null, ?string $expectedRevision = null): array
    {
        return $this->perform($actor, $model, 'preview', ['hours' => $hours, 'version_id' => $version, 'expected_revision' => $expectedRevision]);
    }

    public function revoke(User $actor, Model $model, int $link): object
    {
        return (object) $this->perform($actor, $model, 'preview_revoke', ['link_id' => $link])['link'];
    }

    private function proposeLocked(User $actor, Model $model, array $changes): array
    {
        $allowed = $model instanceof Page
            ? ['title', 'slug', 'template_key', 'content_json', 'featured_image']
            : ['title', 'slug', 'excerpt', 'content', 'meta_json', 'featured_image', 'categories'];
        Validator::make(['changes' => $changes], ['changes' => ['required', 'array:'.implode(',', $allowed)]])->validate();
        $data = $model instanceof Page
            ? (new PageInput($changes, $model, true, true))->validate()
            : (new PostInput($changes, $model, true, true))->validate();
        $payload = $this->history->payload($model);
        $attributes = array_intersect_key($data, array_flip($model->getFillable()));
        foreach (['content_json', 'meta_json'] as $json) {
            if (array_key_exists($json, $attributes)) {
                $attributes[$json] = ContentPatch::apply($model->{$json} ?? [], $attributes[$json]);
            }
        }
        $proposal = $model->newInstance(array_replace($payload['attributes'], $attributes));
        $proposal->status = 'draft';
        $proposal->updated_by = $actor->id;
        $payload['attributes'] = $proposal->attributesToArray();
        if (array_key_exists('featured_image', $data)) {
            $payload['media'] = array_values(array_filter($payload['media'], fn ($m) => $m['collection'] !== 'featured_image'));
            if ($data['featured_image']) {
                $payload['media'][] = ['id' => (int) $data['featured_image'], 'collection' => 'featured_image', 'order' => 0];
            }
        }
        if (array_key_exists('categories', $data)) {
            $other = DB::table('taxonomies')->whereIn('id', $payload['taxonomies'])->where('type', '!=', 'category')->pluck('id')->all();
            $payload['taxonomies'] = array_values(array_unique(array_merge($other, $data['categories'] ?? [])));
        }
        $this->history->capture($model);
        $version = $this->history->storeProposal($model, $payload);

        return $this->versionArray($this->versionRow($model, $version), false);
    }

    private function requestLocked(User $actor, Model $model, array $input): array
    {
        $data = Validator::make($input, ['reviewer_id' => ['required', 'integer', 'exists:users,id'], 'version_id' => ['nullable', 'integer'], 'comment' => ['nullable', 'string', 'max:10000']])->validate();
        abort_unless(User::findOrFail($data['reviewer_id'])->isAdmin(), 422);
        $versionId = $data['version_id'] ?? $this->history->query($model)->where('is_proposal', true)->where('user_id', $actor->id)->latest('id')->value('id');
        if ($versionId) {
            $version = $this->versionRow($model, $versionId);
            abort_unless($actor->isAdmin() || (int) $version->user_id === (int) $actor->id, 403);
            if ($version->is_proposal) {
                abort_unless($version->base_fingerprint && hash_equals($version->base_fingerprint, $this->history->fingerprint($model)), 409, 'Proposal base changed or is unknown. Read current content and create a new proposal before requesting review.');
            }
        } else {
            $this->history->capture($model);
            $versionId = $this->history->query($model)->where('is_proposal', false)->latest('id')->value('id');
        }
        $id = $this->history->query($model, 'editorial_reviews')->insertGetId(['content_type' => $model->getTable(), 'content_id' => $model->id, 'requested_by' => $actor->id, 'reviewer_id' => $data['reviewer_id'], 'version_id' => $versionId, 'fingerprint' => $this->history->fingerprint($model), 'state' => 'pending', 'comment' => $data['comment'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        return (array) $this->reviewRow($model, $id);
    }

    private function decideLocked(User $actor, Model $model, array $input): array
    {
        abort_unless($actor->isAdmin(), 403);
        $data = Validator::make($input, ['review_id' => ['required', 'integer'], 'state' => ['required', Rule::in(['approved', 'changes_requested'])], 'comment' => ['nullable', 'string', 'max:10000']])->validate();
        $review = $this->reviewRow($model, $data['review_id']);
        abort_unless((int) $review->reviewer_id === (int) $actor->id, 403);
        abort_unless($review->state === 'pending', 409, 'This review was already decided ('.$review->state.'). Request a new review for further changes.');
        abort_unless(hash_equals($review->fingerprint, $this->history->fingerprint($model)), 409, __('editorial.conflict'));
        if ($data['state'] === 'approved' && $review->version_id) {
            $version = $this->versionRow($model, $review->version_id);
            if ($version->is_proposal) {
                abort_unless($version->base_fingerprint && hash_equals($version->base_fingerprint, $review->fingerprint), 409, 'Proposal base does not match the requested review. Create a new proposal and review.');
                $this->applyPayload($actor, $model, json_decode($version->payload, true), false);
            }
        }
        if (! empty($data['comment'])) {
            $this->commentLocked($actor, $model, ['review_id' => $review->id, 'body' => $data['comment']]);
        }
        $this->history->query($model, 'editorial_reviews')->where('id', $review->id)->update(['state' => $data['state'], 'updated_at' => now()]);

        return (array) $this->reviewRow($model, $review->id);
    }

    private function commentLocked(User $actor, Model $model, array $input): array
    {
        $data = Validator::make($input, ['review_id' => ['required', 'integer'], 'body' => ['required', 'string', 'max:10000']])->validate();
        $this->reviewRow($model, $data['review_id']);
        $id = DB::table('editorial_review_comments')->insertGetId(['review_id' => $data['review_id'], 'user_id' => $actor->id, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);

        return (array) DB::table('editorial_review_comments')->find($id);
    }

    private function restoreLocked(User $actor, Model $model, array $input): array
    {
        $data = Validator::make($input, ['version_id' => ['required', 'integer'], 'mode' => ['nullable', Rule::in(['draft', 'proposal'])]])->validate();
        $version = $this->versionRow($model, $data['version_id']);
        $payload = json_decode($version->payload, true);
        if (($data['mode'] ?? 'draft') === 'proposal') {
            foreach (['locale', 'translation_group_id', 'created_by'] as $identity) {
                $payload['attributes'][$identity] = $model->{$identity};
            }
            $payload['attributes']['status'] = 'draft';
            $payload['attributes']['updated_by'] = $actor->id;
            $payload['attributes']['published_at'] = $model->published_at?->toISOString();
            $payload['attributes']['expires_at'] = $model->expires_at?->toISOString();
            $this->validatePayload($model, $payload);
            $this->history->capture($model);
            $id = $this->history->storeProposal($model, $payload);

            return ['version' => $this->versionArray($this->versionRow($model, $id), false)];
        }
        abort_if($actor->isEditor() && $model->status === 'published', 403, __('editorial.publish_forbidden'));
        $this->applyPayload($actor, $model, $payload, true);
        $id = $this->history->query($model)->where('is_proposal', false)->latest('id')->value('id');

        return ['version' => $this->versionArray($this->versionRow($model, $id), false), 'content' => ['type' => $model->getTable(), 'id' => $model->id, 'status' => $model->fresh()->status]];
    }

    private function applyPayload(User $actor, Model $model, array $payload, bool $draft): void
    {
        // Publication state/dates survive proposal approval; restoration always becomes a draft.
        $payload['attributes']['status'] = $draft ? 'draft' : $model->status;
        if (! $draft) {
            $payload['attributes']['published_at'] = $model->published_at?->toISOString();
            $payload['attributes']['expires_at'] = $model->expires_at?->toISOString();
        }
        $this->validatePayload($model, $payload);
        $this->history->capture($model);
        $attributes = array_intersect_key($payload['attributes'], array_flip($model->getFillable()));
        foreach (['locale', 'translation_group_id', 'created_by'] as $identity) {
            $attributes[$identity] = $model->{$identity};
        }
        $attributes['updated_by'] = $actor->id;
        $model->fill($attributes)->save();
        $model->media()->detach();
        foreach ($payload['media'] as $media) {
            if (DB::table('media')->where('id', $media['id'])->exists()) {
                $model->attachMedia($media['id'], $media['collection'], $media['order']);
            }
        }
        if (method_exists($model, 'taxonomies')) {
            $model->taxonomies()->sync(DB::table('taxonomies')->whereIn('id', $payload['taxonomies'])->pluck('id')->all());
        }
        if ($model->isBaseLocale()) {
            $model->source_fingerprint = $model->translatableFingerprint();
            $model->source_field_hashes = $model->translatableFieldFingerprints();
            $model->save();
        }
        $this->history->capture($model->fresh());
    }

    private function validatePayload(Model $model, array $payload): void
    {
        $data = $payload['attributes'];
        // A version cannot move the record between translation identities. The
        // persisted locale remains valid for historical installations too.
        unset($data['locale']);
        if ($model instanceof Page) {
            (new PageInput($data, $model, false, true))->validate();
        } else {
            $data['categories'] = DB::table('taxonomies')->whereIn('id', $payload['taxonomies'])->where('type', 'category')->pluck('id')->all();
            (new PostInput($data, $model))->validate();
        }
    }

    private function previewLocked(User $actor, Model $model, array $input): array
    {
        $data = Validator::make($input, ['hours' => ['nullable', 'integer', 'min:1', 'max:168'], 'version_id' => ['nullable', 'integer']])->validate();
        $versionId = $data['version_id'] ?? $this->history->query($model)->where('is_proposal', true)->where('user_id', $actor->id)->latest('id')->value('id');
        if (! $versionId) {
            $this->history->capture($model);
            $versionId = $this->history->query($model)->where('is_proposal', false)->latest('id')->value('id');
        }
        $this->versionRow($model, $versionId);
        $token = Str::random(64);
        $id = $this->history->query($model, 'editorial_preview_links')->insertGetId(['content_type' => $model->getTable(), 'content_id' => $model->id, 'token_hash' => hash('sha256', $token), 'version_id' => $versionId, 'user_id' => $actor->id, 'expires_at' => now()->addHours($data['hours'] ?? 1), 'created_at' => now(), 'updated_at' => now()]);
        $link = (array) $this->history->query($model, 'editorial_preview_links')->where('id', $id)->first();
        unset($link['token_hash']);

        // The site origin, not the request host: the same base every other absolute URL of this site uses.
        $url = SiteUrl::origin().route('editorial.preview', $token, false);

        return ['link' => $link, 'preview_url' => $url, 'url' => $url, 'expires_at' => $link['expires_at']];
    }

    private function revokeLocked(User $actor, Model $model, array $input): array
    {
        $data = Validator::make($input, ['link_id' => ['required', 'integer']])->validate();
        $query = $this->history->query($model, 'editorial_preview_links')->where('id', $data['link_id']);
        $link = $query->first();
        abort_unless($link, 404);
        abort_unless($actor->isAdmin() || (int) $link->user_id === (int) $actor->id, 403);
        $query->update(['revoked_at' => $link->revoked_at ?? now(), 'updated_at' => now()]);
        $result = (array) $query->first();
        unset($result['token_hash']);

        return $result;
    }

    private function difference(Model $model, array $input): array
    {
        $version = $this->versionRow($model, (int) ($input['version_id'] ?? 0));
        $payload = json_decode($version->payload, true);
        $comparison = isset($input['compare_version_id']) ? json_decode($this->versionRow($model, (int) $input['compare_version_id'])->payload, true) : $this->history->payload($model);

        $diff = $version->is_proposal ? $this->history->diff($comparison, $payload) : $this->history->diff($payload, $comparison);
        if (isset($input['field'])) {
            abort_unless(array_key_exists($input['field'], $diff), 404);

            return ['version_id' => (int) $version->id, 'field' => $input['field'], 'before' => $this->fieldRange($diff[$input['field']]['before'], $input), 'after' => $this->fieldRange($diff[$input['field']]['after'], $input)];
        }
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($input['per_page'] ?? 20)));

        return ContentResponse::bounded(['diff' => array_slice($diff, ($page - 1) * $perPage, $perPage, true)]) + ['version_id' => (int) $version->id, 'meta' => $this->pagination(count($diff), $page, $perPage)];
    }

    private function listVersions(Model $model, array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($input['per_page'] ?? $input['limit'] ?? 20)));
        $query = $this->history->query($model);
        $total = $query->count();
        $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : ($page - 1) * $perPage;

        return ['versions' => $query->latest('id')->offset($offset)->limit($perPage)->get()->map(fn ($row) => $this->versionArray($row, false))->all(), 'meta' => $this->pagination($total, $page, $perPage)];
    }

    private function readVersion(Model $model, array $input): array
    {
        $version = $this->versionRow($model, (int) ($input['version_id'] ?? 0));
        $payload = json_decode($version->payload, true);
        if (isset($input['field'])) {
            Validator::make($input, ['field' => ['required', 'string', 'max:2048']])->validate();
            $missing = new \stdClass;
            $value = data_get($payload, $input['field'], $missing);
            abort_if($value === $missing, 404);

            return ['version' => $this->versionArray($version, false), 'field' => $input['field']] + $this->fieldRange($value, $input);
        }

        $bounded = ContentResponse::bounded(['payload' => $payload]);
        if (isset($bounded['recovery'])) {
            $bounded['recovery'] = 'Use content_version with the same version_id, field payload path (attributes.content_json...), offset and length to recover clipped values.';
        }

        return ['version' => $this->versionArray($version, false) + $bounded];
    }

    private function listReviews(Model $model, array $input): array
    {
        if (isset($input['review_id']) && (isset($input['comment_id']) || ($input['field'] ?? null) === 'comment')) {
            $review = $this->reviewRow($model, (int) $input['review_id']);
            if (isset($input['comment_id'])) {
                $comment = DB::table('editorial_review_comments')->where('review_id', $review->id)->where('id', $input['comment_id'])->first();
                abort_unless($comment, 404);

                return ['review_id' => (int) $review->id, 'comment_id' => (int) $comment->id, 'user_id' => $comment->user_id] + $this->fieldRange($comment->body, $input);
            }

            return ['review_id' => (int) $review->id, 'field' => 'comment'] + $this->fieldRange($review->comment, $input);
        }
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($input['per_page'] ?? 20)));
        $query = $this->history->query($model, 'editorial_reviews');
        if (isset($input['review_id'])) {
            $query->where('id', (int) $input['review_id']);
        }
        $total = $query->count();
        $rows = $query->latest('id')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(function ($row) use ($input, $perPage) {
            $review = (array) $row;
            $commentPage = max(1, (int) ($input['comments_page'] ?? 1));
            $comments = DB::table('editorial_review_comments')->where('review_id', $row->id);
            $commentTotal = $comments->count();
            $review['comments'] = $comments->orderBy('id')->offset(($commentPage - 1) * 20)->limit(20)->get()->map(fn ($comment) => (array) $comment)->all();
            $review['comments_meta'] = $this->pagination($commentTotal, $commentPage, 20);
            $bounded = ContentResponse::bounded(['comment' => $review['comment'], 'comments' => $review['comments']], max(200, (int) floor(60000 / $perPage)));
            $review['comment'] = $bounded['comment'];
            $review['comments'] = $bounded['comments'];
            if (isset($bounded['truncated_fields'])) {
                $review['truncated_fields'] = $bounded['truncated_fields'];
            }

            return $review;
        })->all();

        return ContentResponse::bounded(['reviews' => array_map(function ($row) {
            unset($row['fingerprint']);

            return $row;
        }, $rows)]) + ['review_fingerprints' => array_column($rows, 'fingerprint', 'id')] + ['meta' => $this->pagination($total, $page, $perPage), 'recovery' => 'Use content_reviews with review_id and comments_page for comments; comment_id/offset/length recovers body text, or field=comment recovers the request note.'];
    }

    private function fieldRange(mixed $value, array $input): array
    {
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $length = min(is_string($value) ? 2048 : 100, max(1, (int) ($input['length'] ?? (is_string($value) ? 2048 : 100))));
        $total = is_string($value) ? mb_strlen($value) : (is_array($value) ? count($value) : 1);
        $slice = is_string($value) ? mb_substr($value, $offset, $length) : (is_array($value) ? array_slice($value, $offset, $length, true) : $value);
        $result = is_string($slice) ? ['value' => $slice] : ContentResponse::bounded(['value' => $slice]);
        $result['meta'] = ['offset' => $offset, 'length' => $length, 'total' => $total, 'next_offset' => $offset + $length < $total ? $offset + $length : null];

        return $result;
    }

    private function pagination(int $total, int $page, int $perPage): array
    {
        return ['total' => $total, 'current_page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    private function versionRow(Model $model, int $id): object
    {
        $row = $this->history->query($model)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function reviewRow(Model $model, int $id): object
    {
        $row = $this->history->query($model, 'editorial_reviews')->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    private function versionArray(object $row, bool $payload = true): array
    {
        $result = array_replace((array) $row, ['payload' => json_decode($row->payload, true), 'is_proposal' => (bool) $row->is_proposal]);
        if (! $payload) {
            unset($result['payload']);
        }

        return $result;
    }

    private function authorize(User $actor, Model $model, bool $write = false): void
    {
        abort_unless($model instanceof Page || $model instanceof Post, 404);
        abort_unless(in_array($actor->role, config('cms.roles', []), true), 403);
        if ($model instanceof Post) {
            Gate::forUser($actor)->authorize($write ? 'update' : 'view', $model);
        }
    }
}
