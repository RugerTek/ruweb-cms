<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMediaRequest;
use App\Models\Locale;
use App\Models\Media;
use App\Services\Media\MediaImagePipeline;
use App\Services\Media\MediaUsage;
use App\Services\Media\SvgSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class MediaController extends Controller
{
    public function __construct(
        protected MediaImagePipeline $imagePipeline
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $query = Media::query()->with('translations')->latest();

        if ($request->filled('type')) {
            $query->where('mime_type', 'like', $request->input('type').'/%');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('original_filename', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('alt', 'like', "%{$search}%")
                    ->orWhereHas('translations', fn ($translations) => $translations
                        ->where('title', 'like', "%{$search}%")->orWhere('alt', 'like', "%{$search}%"));
            });
        }

        if ($month = $this->normalizedMonthFilter($request->input('month'))) {
            $query->whereBetween('created_at', [$month->startOfMonth(), $month->endOfMonth()]);
        }

        $perPage = 24;
        if ($request->wantsJson()) {
            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        }

        $media = $query->paginate($perPage)->withQueryString();

        if ($request->wantsJson()) {
            $this->useMetadataLocale($request);

            return response()->json($media);
        }

        return view('admin.media.index', [
            'media' => $media,
            'mediaLocales' => Locale::where('is_active', true)->orderByDesc('is_base')->orderBy('sort_order')->get(),
            'mediaBaseLocale' => Locale::baseCode(),
            'availableMonths' => $this->availableMonths(),
            'hasActiveFilters' => $request->filled('type')
                || $request->filled('search')
                || $request->filled('month'),
        ]);
    }

    /**
     * Validate a `YYYY-MM` filter value and turn it into a date, or null when unusable.
     */
    protected function normalizedMonthFilter(mixed $month): ?CarbonImmutable
    {
        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Months that actually contain media, newest first, labelled in the admin locale.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function availableMonths(): array
    {
        $locale = app()->getLocale();

        return Media::query()
            ->selectRaw('substr(created_at, 1, 7) as media_month')
            ->distinct()
            ->orderByDesc('media_month')
            ->pluck('media_month')
            ->filter(fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value))
            ->map(fn (string $value) => [
                'value' => $value,
                'label' => Str::ucfirst(
                    CarbonImmutable::createFromFormat('Y-m-d', $value.'-01')
                        ->locale($locale)
                        ->isoFormat('MMMM YYYY')
                ),
            ])
            ->values()
            ->all();
    }

    public function store(StoreMediaRequest $request): JsonResponse
    {
        $uploadedFiles = $request->file('files') ?: [$request->file('file')];
        $uploadedFiles = array_values(array_filter((array) $uploadedFiles));

        $allowedExtensions = config('cms.upload.allowed_extensions');
        $createdMedia = [];

        foreach ($uploadedFiles as $file) {
            $validationError = $this->validateUploadedFile($file, $allowedExtensions);
            if ($validationError !== null) {
                return response()->json(['error' => $validationError], 422);
            }
        }

        foreach ($uploadedFiles as $file) {
            $extension = strtolower($file->getClientOriginalExtension());

            $filename = Str::uuid().'.'.$extension;
            $directory = 'media/'.now()->format('Y/m');
            $path = $file->storeAs($directory, $filename, 'public');

            $createdMedia[] = Media::create([
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'extension' => $extension,
                'size' => $file->getSize(),
                'alt' => $request->input('alt'),
                'title' => $request->input('title', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
                'disk' => 'public',
                'uploaded_by' => $request->user()->id,
            ]);

            try {
                $this->imagePipeline->processUploadedMedia($createdMedia[array_key_last($createdMedia)]);
                $createdMedia[array_key_last($createdMedia)]->refresh();
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (count($createdMedia) === 1) {
            return response()->json($createdMedia[0], 201);
        }

        return response()->json(['data' => $createdMedia], 201);
    }

    protected function validateUploadedFile(UploadedFile $file, array $allowedExtensions): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $allowedExtensions, true)) {
            return __('admin.media_error_extension_not_allowed');
        }

        if ($extension !== 'svg') {
            return null;
        }

        try {
            $content = app(SvgSanitizer::class)->sanitize((string) file_get_contents($file->getRealPath()));
            if (file_put_contents($file->getRealPath(), $content) === false) {
                return __('admin.media_error_svg_malicious');
            }
        } catch (\InvalidArgumentException $exception) {
            return __('admin.media_error_svg_malicious');
        }

        return null;
    }

    public function show(Request $request, Media $media): JsonResponse
    {
        $this->useMetadataLocale($request);

        return response()->json($media->load('translations')->toArray() + [
            'usage' => app(MediaUsage::class)->references($media),
        ]);
    }

    public function update(Request $request, Media $media): JsonResponse
    {
        $validated = $request->validate([
            'alt' => [Rule::prohibitedIf($request->has('metadata')), 'nullable', 'string', 'max:255'],
            'title' => [Rule::prohibitedIf($request->has('metadata')), 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array:'.implode(',', $this->metadataLocales())],
            'metadata.*' => ['array:alt,title'],
            'metadata.*.alt' => ['nullable', 'string', 'max:255'],
            'metadata.*.title' => ['nullable', 'string', 'max:255'],
        ], [
            'max.string' => __('admin.editor_validation_max_string'),
            'string' => __('admin.editor_validation_string'),
            'array' => __('admin.media_metadata_invalid'),
        ]);

        DB::transaction(function () use ($media, $validated) {
            if (! array_key_exists('metadata', $validated)) {
                $media->update($validated);

                return;
            }
            foreach ($validated['metadata'] as $locale => $fields) {
                $media->updateMetadata($fields, $locale);
            }
        });

        return response()->json($media->refresh()->load('translations'));
    }

    protected function metadataLocales(): array
    {
        return array_values(array_unique([Locale::baseCode(), ...Locale::where('is_active', true)->pluck('code')->all()]));
    }

    protected function useMetadataLocale(Request $request): void
    {
        $locales = $this->metadataLocales();
        $validated = $request->validate(['locale' => ['sometimes', 'string', Rule::in($locales)]]);
        $locale = $validated['locale'] ?? app()->getLocale();
        app()->setLocale(in_array($locale, $locales, true) ? $locale : Locale::baseCode());
    }

    public function destroy(Media $media): JsonResponse
    {
        $media->delete();

        return response()->json(['message' => __('admin.media_deleted')]);
    }
}
