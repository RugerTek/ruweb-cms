<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMediaRequest;
use App\Models\Locale;
use App\Models\Media;
use App\Services\Media\MediaOperations;
use App\Services\Media\MediaUsage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class MediaController extends Controller
{
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
        $createdMedia = app(MediaOperations::class)->upload($request->user(), $request->all());

        return count($createdMedia) === 1
            ? response()->json($createdMedia[0], 201)
            : response()->json(['data' => $createdMedia], 201);
    }

    public function show(Request $request, Media $media): JsonResponse
    {
        $this->useMetadataLocale($request);

        return response()->json($media->load('translations')->toArray() + [
            'usage' => app(MediaUsage::class)->references($media),
            'fingerprint' => app(MediaOperations::class)->fingerprint($media),
        ]);
    }

    public function update(Request $request, Media $media): JsonResponse
    {
        $request->validate(['base_fingerprint' => ['sometimes', 'string', 'size:64']]);
        $media = app(MediaOperations::class)->update(
            $request->user(), $media, $request->all(), $request->input('base_fingerprint')
        );

        return response()->json($media);
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

    public function destroy(Request $request, Media $media): JsonResponse
    {
        app(MediaOperations::class)->delete($request->user(), $media);

        return response()->json(['message' => __('admin.media_deleted')]);
    }
}
