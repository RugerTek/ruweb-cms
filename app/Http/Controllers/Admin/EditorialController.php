<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Content\ContentEditLock;
use App\Services\Editorial\EditorialHistory;
use App\Support\AdminCapabilities;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EditorialController extends Controller
{
    public function __construct(private EditorialHistory $history) {}

    public function index(Request $request, string $type, int $id)
    {
        $model = $this->history->model($type, $id);
        $this->history->capture($model);
        $versions = $this->history->query($model)->latest('id')->paginate(20);
        $selected = $request->integer('version');
        $version = $selected ? $this->history->query($model)->where('id', $selected)->first() : null;
        $locks = app(ContentEditLock::class);
        $editorLease = $locks->synchronized($model, function () use ($locks, $model, $request) {
            $lock = $locks->active($model);

            return $lock && $locks->sameSession($lock, $request) ? ['token' => $lock->token] : null;
        });

        return view('admin.editorial.index', compact('model', 'versions', 'version') + [
            'diff' => $version ? $this->history->diff($version->is_proposal ? $this->history->payload($model) : json_decode($version->payload, true), $version->is_proposal ? json_decode($version->payload, true) : $this->history->payload($model)) : [],
            'reviews' => $this->history->query($model, 'editorial_reviews')->latest('id')->get(),
            'comments' => DB::table('editorial_review_comments')->whereIn('review_id', $this->history->query($model, 'editorial_reviews')->pluck('id'))->orderBy('id')->get()->groupBy('review_id'),
            'reviewers' => User::where('role', 'admin')->get(),
            'authors' => User::all()->keyBy('id'),
            'editorLease' => $editorLease,
            'links' => $this->history->query($model, 'editorial_preview_links')->whereNull('revoked_at')->where('expires_at', '>', now())->get(),
            'stale' => $model->staleTranslatableFields(),
        ]);
    }

    public function restore(Request $request, string $type, int $id, int $version)
    {
        $model = $this->history->model($type, $id);
        abort_if($request->user()->isEditor() && $model->status === 'published', 403, __('editorial.publish_forbidden'));
        $this->history->restore($model, $version);

        return redirect(app(AdminCapabilities::class)->contentUrl($model))->with('success', __('editorial.restored'));
    }

    public function draft(Request $request, string $type, int $id)
    {
        $model = $this->history->model($type, $id);
        $query = $this->history->query($model, 'editorial_drafts')->where('user_id', $request->user()->id);
        if ($request->isMethod('GET')) {
            $row = $query->where('expires_at', '>', now())->first();

            return response()->json(['draft' => $row ? json_decode($row->payload, true) : null,
                'updated_at' => $row ? Carbon::parse($row->updated_at)->getTimestampMs() : null,
                'base_fingerprint' => $row?->base_fingerprint, 'fingerprint' => $this->history->fingerprint($model),
                'conflict' => $row && $row->base_fingerprint !== $this->history->fingerprint($model)])->header('Cache-Control', 'no-store, private');
        }
        if ($request->isMethod('DELETE')) {
            $query->delete();

            return response()->json(['discarded' => true]);
        }
        $data = $request->validate(['payload' => ['required', 'array', 'max:5000'], 'payload.*' => ['array', 'size:2'],
            'payload.*.0' => ['string', 'max:2048'], 'payload.*.1' => ['string', 'max:500000'], 'base_fingerprint' => ['required', 'string', 'size:64']]);
        // Draft payload is never mass-assigned to content. It is user-scoped recovery data only.
        $payload = array_values(array_filter($data['payload'], fn ($pair) => ! in_array($pair[0], ['_token', '_method', '_editor_lock', '_editor_base', 'status', 'return'])));
        DB::table('editorial_drafts')->where('expires_at', '<=', now())->delete();
        DB::table('editorial_drafts')->updateOrInsert(['content_type' => $type, 'content_id' => $id, 'user_id' => $request->user()->id],
            ['payload' => json_encode($payload), 'base_fingerprint' => $data['base_fingerprint'], 'expires_at' => now()->addDays(14), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['autosaved' => true]);
    }

    public function review(Request $request, string $type, int $id)
    {
        $model = $this->history->model($type, $id);
        $data = $request->validate(['reviewer_id' => ['required', 'integer', 'exists:users,id'], 'comment' => ['nullable', 'string', 'max:10000']]);
        abort_unless(User::find($data['reviewer_id'])->isAdmin(), 422);
        $this->history->query($model, 'editorial_reviews')->insert(['content_type' => $type, 'content_id' => $id, 'requested_by' => $request->user()->id,
            'reviewer_id' => $data['reviewer_id'], 'version_id' => $this->history->query($model)->where('is_proposal', true)->where('user_id', $request->user()->id)->latest('id')->value('id'), 'fingerprint' => $this->history->fingerprint($model), 'state' => 'pending', 'comment' => $data['comment'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('editorial.review_requested'));
    }

    public function decide(Request $request, string $type, int $id, int $review)
    {
        $model = $this->history->model($type, $id);
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['state' => ['required', 'in:approved,changes_requested'], 'comment' => ['nullable', 'string', 'max:10000']]);
        DB::transaction(function () use ($request, $model, $review, $data) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            $row = $this->history->query($model, 'editorial_reviews')->where('id', $review)->lockForUpdate()->first();
            abort_unless($row && $row->reviewer_id === $request->user()->id && $row->state === 'pending', 403);
            abort_unless(hash_equals($row->fingerprint, $this->history->fingerprint($model)), 409, __('editorial.conflict'));
            if ($data['state'] === 'approved' && $row->version_id) {
                $this->history->restore($model, (int) $row->version_id, false);
            }
            if (! empty($data['comment'])) {
                DB::table('editorial_review_comments')->insert(['review_id' => $review, 'user_id' => $request->user()->id, 'body' => $data['comment'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->history->query($model, 'editorial_reviews')->where('id', $review)->update(['state' => $data['state'], 'updated_at' => now()]);
        });

        return back()->with('success', __('editorial.review_decided'));
    }

    public function comment(Request $request, string $type, int $id, int $review)
    {
        $model = $this->history->model($type, $id);
        abort_unless($this->history->query($model, 'editorial_reviews')->where('id', $review)->exists(), 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        DB::table('editorial_review_comments')->insert(['review_id' => $review, 'user_id' => $request->user()->id, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('editorial.comment_added'));
    }

    public function approveField(Request $request, string $type, int $id)
    {
        $data = $request->validate(['field' => ['required', 'string', 'max:500'], 'base_hash' => ['required', 'string', 'size:64']]);
        $model = $this->history->model($type, $id);
        abort_if($model->isBaseLocale(), 422);
        abort_if($request->user()->isEditor() && $model->status === 'published', 403, __('editorial.publish_forbidden'));
        DB::transaction(function () use ($model, $data) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            $base = $model->baseTranslation();
            $hashes = $base?->translatableFieldFingerprints() ?? [];
            abort_unless(isset($hashes[$data['field']]) || array_key_exists($data['field'], $model->source_field_hashes ?? []), 422);
            $hashes[$data['field']] ??= $model::removedSourceFieldHash();
            abort_unless(hash_equals($hashes[$data['field']], $data['base_hash']), 409, __('editorial.conflict'));
            $model->source_field_hashes = array_replace($model->source_field_hashes ?? [], [$data['field'] => $data['base_hash']]);
            if ($model->staleTranslatableFields() === []) {
                $model->source_fingerprint = $base->translatableFingerprint();
                $model->translation_status = null;
            }
            $model->save();
        });

        return back()->with('success', __('editorial.field_approved'));
    }

    public function share(Request $request, string $type, int $id)
    {
        $model = $this->history->model($type, $id);
        $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:168'], 'version_id' => ['nullable', 'integer']]);
        if (! empty($data['version_id'])) {
            abort_unless($this->history->query($model)->where('id', $data['version_id'])->exists(), 422);
        }
        $token = Str::random(64);
        $this->history->query($model, 'editorial_preview_links')->insert(['content_type' => $type, 'content_id' => $id, 'token_hash' => hash('sha256', $token),
            'version_id' => $data['version_id'] ?? $this->history->query($model)->where('is_proposal', true)->where('user_id', $request->user()->id)->latest('id')->value('id'),
            'user_id' => $request->user()->id, 'expires_at' => now()->addHours($data['hours']), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('previewUrl', route('editorial.preview', $token));
    }

    public function revoke(Request $request, string $type, int $id, int $link)
    {
        $model = $this->history->model($type, $id);
        $query = $this->history->query($model, 'editorial_preview_links')->where('id', $link);
        $row = $query->first();
        abort_unless($row && ($request->user()->isAdmin() || $row->user_id === $request->user()->id), 403);
        $query->update(['revoked_at' => now()]);

        return back()->with('success', __('editorial.revoked'));
    }

    public function calendar(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));
        abort_unless(is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 422);
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $items = collect();
        foreach ([Page::class, Post::class] as $class) {
            $items = $items->merge($class::where(fn ($q) => $q->whereBetween('published_at', [$start, $end])->orWhereBetween('expires_at', [$start, $end]))->get());
        }

        return view('admin.editorial.calendar', ['items' => $items->sortBy('published_at'), 'month' => $month]);
    }
}
