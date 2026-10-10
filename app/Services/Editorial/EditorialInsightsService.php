<?php

namespace App\Services\Editorial;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Support\AdminCapabilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class EditorialInsightsService
{
    public function summary(): array
    {
        $baseLocale = Locale::baseCode();
        $records = $this->baseRecords($baseLocale);
        $secondaryLocales = Locale::where('is_active', true)
            ->where('is_public', true)
            ->where('code', '!=', $baseLocale)
            ->orderBy('sort_order')
            ->get();

        $completionIssues = $this->completionIssues($records);
        [$pendingTranslations, $outdatedTranslations, $coverage] = $this->translationInsights($records, $secondaryLocales);

        return [
            'editorialRows' => $this->editorialRows($records),
            'hasSecondaryPublicLocales' => $secondaryLocales->isNotEmpty(),
            'secondaryPublicLocales' => $secondaryLocales,
            'completionIssues' => $completionIssues,
            'pendingTranslations' => $pendingTranslations,
            'outdatedTranslations' => $outdatedTranslations,
            'translationCoverage' => $coverage,
            'pendingTranslationsCount' => $pendingTranslations->count(),
            'outdatedTranslationsCount' => $outdatedTranslations->count(),
            'baseRecordCount' => $records->count(),
        ];
    }

    private function editorialRows(Collection $records): Collection
    {
        return $records->map(function (array $record): array {
            $model = $record['model'];
            $meta = $model->meta();
            $issues = [
                'featured_image' => ! $model->featuredImage(),
                'seo_title' => ! filled($meta['title'] ?? null),
                'seo_description' => ! filled($meta['description'] ?? null),
            ];

            return [
                'type' => $record['type'],
                'type_label' => $record['type_label'],
                'label' => $record['label'],
                'edit_url' => $record['edit_url'],
                'issues' => $issues,
                'issue_count' => collect($issues)->filter()->count(),
            ];
        })
            ->filter(fn (array $record): bool => $record['issue_count'] > 0)
            ->sortBy([
                ['issue_count', 'desc'],
                ['type', 'asc'],
                ['label', 'asc'],
            ])
            ->values();
    }

    private function baseRecords(string $baseLocale): Collection
    {
        return Page::query()
            ->where('locale', $baseLocale)
            ->with('translations')
            ->get()
            ->map(fn (Page $page): array => $this->wrapRecord($page, 'page'))
            ->concat(
                Post::query()
                    ->where('locale', $baseLocale)
                    ->with('translations')
                    ->get()
                    ->map(fn (Post $post): array => $this->wrapRecord($post, 'post'))
            )
            ->values();
    }

    private function wrapRecord(Model $record, string $type): array
    {
        return [
            'type' => $type,
            'model' => $record,
            'label' => $record->title,
            'edit_url' => app(AdminCapabilities::class)->contentUrl($record),
            'type_label' => __('admin.editorial_type_'.$type),
        ];
    }

    private function completionIssues(Collection $records): Collection
    {
        $issues = collect([
            'featured_image' => [
                'key' => 'featured_image',
                'title' => __('admin.editorial_issue_featured_image_title'),
                'description' => __('admin.editorial_issue_featured_image_description'),
                'items' => collect(),
            ],
            'seo_title' => [
                'key' => 'seo_title',
                'title' => __('admin.editorial_issue_seo_title_title'),
                'description' => __('admin.editorial_issue_seo_title_description'),
                'items' => collect(),
            ],
            'seo_description' => [
                'key' => 'seo_description',
                'title' => __('admin.editorial_issue_seo_description_title'),
                'description' => __('admin.editorial_issue_seo_description_description'),
                'items' => collect(),
            ],
        ]);

        foreach ($records as $record) {
            $model = $record['model'];
            $meta = $model->meta();

            if (! $model->featuredImage()) {
                $issues['featured_image']['items']->push($this->completionItem($record));
            }

            if (! filled($meta['title'] ?? null)) {
                $issues['seo_title']['items']->push($this->completionItem($record));
            }

            if (! filled($meta['description'] ?? null)) {
                $issues['seo_description']['items']->push($this->completionItem($record));
            }
        }

        return $issues->map(function (array $issue): array {
            $items = $issue['items']->sortBy([
                ['type', 'asc'],
                ['label', 'asc'],
            ])->values();

            return $issue + [
                'count' => $items->count(),
                'items' => $items,
            ];
        })->values();
    }

    private function completionItem(array $record): array
    {
        return [
            'type' => $record['type'],
            'type_label' => $record['type_label'],
            'label' => $record['label'],
            'edit_url' => $record['edit_url'],
        ];
    }

    private function translationInsights(Collection $records, Collection $secondaryLocales): array
    {
        $pending = collect();
        $outdated = collect();
        $coverage = collect();

        foreach ($secondaryLocales as $locale) {
            $published = 0;

            foreach ($records as $record) {
                $model = $record['model'];
                $state = $model->derivedTranslationState($locale->code);

                if ($state === 'published') {
                    $published++;

                    continue;
                }

                if (in_array($state, ['missing', 'draft', 'needs_review'], true)) {
                    $pending->push($this->translationItem($record, $locale->code, $state));

                    continue;
                }

                if ($state === 'outdated') {
                    $outdated->push($this->translationItem($record, $locale->code, $state));
                }
            }

            $total = $records->count();
            $coverage->push([
                'locale' => $locale->code,
                'locale_name' => $locale->name,
                'published' => $published,
                'total' => $total,
                'percent' => $total > 0 ? (int) round(($published / $total) * 100) : 0,
            ]);
        }

        return [
            $pending->sortBy([
                ['locale', 'asc'],
                ['type', 'asc'],
                ['label', 'asc'],
            ])->values(),
            $outdated->sortBy([
                ['locale', 'asc'],
                ['type', 'asc'],
                ['label', 'asc'],
            ])->values(),
            $coverage,
        ];
    }

    private function translationItem(array $record, string $localeCode, string $state): array
    {
        $model = $record['model'];
        $translation = $model->translations->firstWhere('locale', $localeCode);

        return [
            'type' => $record['type'],
            'type_label' => $record['type_label'],
            'label' => $record['label'],
            'locale' => $localeCode,
            'state' => $state,
            'state_label' => __('admin.editorial_translation_state_'.$state),
            'action' => $this->translationAction($record['type'], $model, $translation, $localeCode, $state),
        ];
    }

    private function translationAction(string $type, Model $base, ?Model $translation, string $localeCode, string $state): ?array
    {
        if ($state === 'missing') {
            if (! app(AdminCapabilities::class)->liveEditor()) {
                return null;
            }

            return [
                'kind' => 'create',
                'method' => 'post',
                'label' => __('admin.action_create'),
                'url' => $type === 'page'
                    ? route('admin.pages.translate', [$base, $localeCode])
                    : route('admin.posts.translate', [$base, $localeCode]),
            ];
        }

        if (! $translation) {
            return null;
        }

        return [
            'kind' => 'edit',
            'method' => 'get',
            'label' => $state === 'outdated'
                ? __('admin.action_update')
                : __('admin.action_edit'),
            'url' => app(AdminCapabilities::class)->contentUrl($translation),
        ];
    }
}
