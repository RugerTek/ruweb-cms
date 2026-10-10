<?php

namespace App\Services\Media;

use App\Http\Requests\Admin\StoreMediaRequest;
use App\Models\Locale;
use App\Models\Media;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Shared media rules for the admin, REST and RPC adapters. */
final class MediaOperations
{
    public function authorize(User $actor): void
    {
        if (! in_array($actor->role, config('cms.roles', []), true)) {
            throw new AuthorizationException('Unauthorized role for media operation.');
        }
    }

    public function locales(): array
    {
        return array_values(array_unique([Locale::baseCode(), ...Locale::where('is_active', true)->pluck('code')->all()]));
    }

    public function fingerprint(Media $media): string
    {
        $metadata = $media->load('translations')->metadata;
        ksort($metadata);

        return hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function upload(User $actor, array $input): array
    {
        $this->authorize($actor);
        $input = Validator::make($input, (new StoreMediaRequest)->rules())->validate();
        $files = array_values(array_filter((array) ($input['files'] ?? [$input['file'] ?? null])));
        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, config('cms.upload.allowed_extensions', []), true)) {
                throw ValidationException::withMessages(['file' => __('admin.media_error_extension_not_allowed')]);
            }
            // Apply the configured limit for this file's actual MIME family.
            $max = str_starts_with((string) $file->getMimeType(), 'image/')
                ? config('cms.upload.image_max_size') : config('cms.upload.document_max_size');
            Validator::make(['file' => $file], ['file' => ['file', 'max:'.$max]])->validate();
            if ($extension === 'svg') {
                try {
                    $content = app(SvgSanitizer::class)->sanitize((string) file_get_contents($file->getRealPath()));
                    if (file_put_contents($file->getRealPath(), $content) === false) {
                        throw new \InvalidArgumentException;
                    }
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['file' => __('admin.media_error_svg_malicious')]);
                }
            }
        }

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $input, $files) {
            $created = [];
            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $filename = Str::uuid().'.'.$extension;
                $path = $file->storeAs('media/'.now()->format('Y/m'), $filename, 'public');
                $this->onUploadRollback(fn () => Storage::disk('public')->delete($path));
                try {
                    $media = Media::create([
                        'filename' => $filename, 'original_filename' => $file->getClientOriginalName(),
                        'path' => $path, 'mime_type' => $file->getMimeType(), 'extension' => $extension,
                        'size' => $file->getSize(), 'alt' => $input['alt'] ?? null,
                        'title' => $input['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                        'disk' => 'public', 'uploaded_by' => $actor->id,
                    ]);
                } catch (Throwable $exception) {
                    Storage::disk('public')->delete($path);
                    throw $exception;
                }
                $this->onUploadRollback(fn () => app(MediaImagePipeline::class)->deleteRelatedFiles($media));
                try {
                    app(MediaImagePipeline::class)->processUploadedMedia($media);
                } catch (Throwable $exception) {
                    report($exception);
                }
                $created[] = $media->refresh()->load('translations');
            }

            return $created;
        }));
    }

    private function onUploadRollback(callable $cleanup): void
    {
        // Register on every active enclosing transaction: an inner savepoint may
        // commit while a creation receipt or the caller later rolls back.
        $connection = DB::connection()->getName();
        foreach (app('db.transactions')->callbackApplicableTransactions() as $transaction) {
            if ($transaction->connection === $connection) {
                $transaction->addCallbackForRollback($cleanup);
            }
        }
    }

    public function update(User $actor, Media $media, array $input, ?string $base = null): Media
    {
        $this->authorize($actor);
        $validated = Validator::make($input, [
            'locale' => ['sometimes', 'string', Rule::in($this->locales())],
            'alt' => [Rule::prohibitedIf(array_key_exists('metadata', $input)), 'nullable', 'string', 'max:255'],
            'title' => [Rule::prohibitedIf(array_key_exists('metadata', $input)), 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array:'.implode(',', $this->locales())],
            'metadata.*' => ['array:alt,title'],
            'metadata.*.alt' => ['nullable', 'string', 'max:255'],
            'metadata.*.title' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($media, $validated, $base) {
            $media = Media::query()->lockForUpdate()->findOrFail($media->id);
            if ($base !== null && ! hash_equals($this->fingerprint($media), $base)) {
                abort(409, __('editorial.conflict'));
            }
            $metadata = $validated['metadata'] ?? [($validated['locale'] ?? Locale::baseCode()) => array_intersect_key($validated, array_flip(['alt', 'title']))];
            foreach ($metadata as $locale => $fields) {
                $media->updateMetadata($fields, $locale);
            }

            return $media->refresh()->load('translations');
        }, 3));
    }

    public function usages(User $actor, Media $media): array
    {
        $this->authorize($actor);

        return app(MediaUsage::class)->references($media);
    }

    public function delete(User $actor, Media $media): void
    {
        $this->authorize($actor);
        EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($media) {
            // Model events enforce usage protection and remove all related files.
            Media::query()->lockForUpdate()->findOrFail($media->id)->delete();
        }));
    }
}
