<?php

namespace App\Console\Commands;

use App\Support\AdminCapabilities;
use App\Support\EssentialEditorGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Deterministic checks for the rules written in docs/. Every rule here is one a grep
 * can decide; judgement calls belong to the `review-conventions` skill. Consumed by
 * .githooks/pre-commit, CI and tests/Feature/ConventionsTest.php.
 */
class CmsCheckConventionsCommand extends Command
{
    protected $signature = 'cms:check-conventions
        {--staged : Only consider files staged for commit}
        {--since= : Only consider files changed since this git ref}';

    protected $description = 'Check the codebase against the conventions documented in docs/.';

    /** @var list<array{rule: string, file: string, message: string, doc: string}> */
    private array $violations = [];

    public function handle(): int
    {
        $this->violations = [];
        app(AdminCapabilities::class)->profile();
        $scope = $this->scopedFiles();

        $this->formRoutesAreThrottled($scope);
        $this->formTemplatesCarryAntiSpam($scope);
        $this->registeredFormsAreComplete($scope);
        $this->adminTranslationsMatch($scope);
        $this->registeredTemplatesHaveViews($scope);
        $this->registeredSectionsHaveEditors($scope);
        $this->newEssentialEditorsAreJustified();
        $this->templatesDoNotHardcodeImages($scope);
        $this->seedersDoNotStoreAbsoluteUrls($scope);
        $this->versionMatchesChangelog($scope);

        if ($this->violations === []) {
            $this->info('Conventions OK'.($scope === null ? '' : ' (scoped to '.count($scope).' changed file(s))'));

            return self::SUCCESS;
        }

        foreach ($this->violations as $violation) {
            $this->error(sprintf('%s  %s', $violation['file'], $violation['message']));
            $this->line(sprintf('    rule: %s — %s', $violation['rule'], $violation['doc']));
        }

        $this->newLine();
        $this->error(count($this->violations).' convention violation(s).');

        return self::FAILURE;
    }

    /**
     * Files to consider, or null for the whole repository. A scoped run still checks a
     * rule when any file that rule reads was touched, so partial commits stay honest.
     *
     * @return list<string>|null
     */
    private function scopedFiles(): ?array
    {
        $command = match (true) {
            (bool) $this->option('staged') => 'git diff --cached --name-only --diff-filter=ACMR',
            filled($this->option('since')) => 'git diff --name-only --diff-filter=ACMR '.escapeshellarg((string) $this->option('since')),
            default => null,
        };

        if ($command === null) {
            return null;
        }

        exec($command, $output, $status);

        if ($status !== 0) {
            $this->warn('Could not read the git file list; checking everything instead.');

            return null;
        }

        return array_values(array_filter(array_map('trim', $output)));
    }

    /** True when the scope is the whole repo or contains a file matching any fragment. */
    private function touches(?array $scope, string ...$fragments): bool
    {
        if ($scope === null) {
            return true;
        }

        foreach ($scope as $file) {
            foreach ($fragments as $fragment) {
                if (Str::contains($file, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function flag(string $rule, string $file, string $message, string $doc): void
    {
        $this->violations[] = compact('rule', 'file', 'message', 'doc');
    }

    /** Every public form POST route carries the shared per-IP limiter. */
    private function formRoutesAreThrottled(?array $scope): void
    {
        if (! $this->touches($scope, 'routes/')) {
            return;
        }

        foreach (File::files(base_path('routes')) as $file) {
            foreach (file($file->getPathname()) as $index => $line) {
                if (! Str::contains($line, "Route::post('/forms/")) {
                    continue;
                }

                if (! Str::contains($line, 'throttle:forms')) {
                    $this->flag(
                        'form-route-throttle',
                        'routes/'.$file->getFilename().':'.($index + 1),
                        'public form route without throttle:forms',
                        'docs/content/forms.md — Anti-Spam Layer'
                    );
                }
            }
        }
    }

    /** A template posting to a form route renders the honeypot and reCAPTCHA components. */
    private function formTemplatesCarryAntiSpam(?array $scope): void
    {
        if (! $this->touches($scope, 'resources/views/')) {
            return;
        }

        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = $file->getContents();

            if (! Str::contains($contents, 'forms.') || ! Str::contains($contents, '<form')) {
                continue;
            }

            if (! preg_match("/forms\.[a-z0-9_-]+\.store/", $contents)) {
                continue;
            }

            $relative = Str::after($file->getPathname(), base_path().'/');

            foreach (['<x-forms.honeypot' => 'honeypot', '<x-forms.recaptcha' => 'reCAPTCHA'] as $needle => $label) {
                if (! Str::contains($contents, $needle)) {
                    $this->flag(
                        'form-template-anti-spam',
                        $relative,
                        "public form without the {$label} component",
                        'docs/content/forms.md — Anti-Spam Layer'
                    );
                }
            }

            if (! Str::contains($contents, '@csrf')) {
                $this->flag('form-template-anti-spam', $relative, 'public form without @csrf', 'docs/content/forms.md');
            }
        }
    }

    /** Each registered form owns its admin partials and its tab label in every locale. */
    private function registeredFormsAreComplete(?array $scope): void
    {
        if (! $this->touches($scope, 'config/cms.php', 'resources/views/admin/submissions', 'lang/')) {
            return;
        }

        foreach (array_keys((array) config('cms.forms', [])) as $key) {
            foreach (['_columns', '_row', '_detail'] as $partial) {
                $path = resource_path("views/admin/submissions/{$key}/{$partial}.blade.php");

                if (! File::exists($path)) {
                    $this->flag(
                        'form-admin-partials',
                        "resources/views/admin/submissions/{$key}/{$partial}.blade.php",
                        "missing partial for the registered form '{$key}'",
                        'docs/content/forms.md — Admin: Submissions'
                    );
                }
            }

            foreach ($this->adminLocales() as $locale) {
                $translations = $this->translations($locale);

                if (! isset($translations["forms.{$key}"])) {
                    $this->flag(
                        'form-tab-translation',
                        "lang/{$locale}/admin.php",
                        "missing admin.forms.{$key} for the registered form '{$key}'",
                        'docs/admin/translations.md'
                    );
                }
            }
        }
    }

    /** Admin translation files stay key-for-key identical across locales. */
    private function adminTranslationsMatch(?array $scope): void
    {
        if (! $this->touches($scope, 'lang/')) {
            return;
        }

        $locales = $this->adminLocales();
        $reference = array_shift($locales);

        if ($reference === null) {
            return;
        }

        $referenceKeys = array_keys($this->translations($reference));

        foreach ($locales as $locale) {
            $keys = array_keys($this->translations($locale));

            foreach (array_diff($referenceKeys, $keys) as $missing) {
                $this->flag(
                    'admin-translation-parity',
                    "lang/{$locale}/admin.php",
                    "missing key '{$missing}' (present in {$reference})",
                    'docs/admin/translations.md — The Parity Rule'
                );
            }

            foreach (array_diff($keys, $referenceKeys) as $extra) {
                $this->flag(
                    'admin-translation-parity',
                    "lang/{$reference}/admin.php",
                    "missing key '{$extra}' (present in {$locale})",
                    'docs/admin/translations.md — The Parity Rule'
                );
            }
        }
    }

    /** Every registered template resolves to a view; a missing one falls back silently. */
    private function registeredTemplatesHaveViews(?array $scope): void
    {
        if (! $this->touches($scope, 'config/cms.php', 'resources/views/templates')) {
            return;
        }

        foreach (array_keys((array) config('cms.templates', [])) as $key) {
            if (! File::exists(resource_path("views/templates/{$key}.blade.php"))) {
                $this->flag(
                    'template-view-missing',
                    "resources/views/templates/{$key}.blade.php",
                    "registered template '{$key}' has no view and would fall back to default",
                    'docs/content/templates.md — The View'
                );
            }
        }
    }

    /** Specialized fields cannot silently fall back to heading/body in full. */
    private function registeredSectionsHaveEditors(?array $scope): void
    {
        if (! $this->touches($scope, 'config/cms.php', 'resources/views/admin/pages/sections') || ! app(AdminCapabilities::class)->liveEditor()) {
            return;
        }
        foreach (config('cms.templates', []) as $key => $template) {
            foreach ($template['sections'] ?? [] as $section) {
                if (app(AdminCapabilities::class)->requiresSectionEditor($template, $section)
                    && app(AdminCapabilities::class)->sectionEditor($section) === null) {
                    $this->flag('section-editor-missing', "resources/views/admin/pages/sections/{$key}_{$section}.blade.php", "custom fields in '{$key}.{$section}' require an editor in full", 'docs/content/templates.md — Section Editor Partials');
                }
            }
        }
    }

    /** New specialized UI in Essential needs an explicit, reviewed reason. */
    private function newEssentialEditorsAreJustified(): void
    {
        try {
            $files = app(EssentialEditorGuard::class)->violations(
                base_path(),
                app(AdminCapabilities::class)->profile(),
                (bool) $this->option('staged'),
                $this->option('since'),
                array_keys(config('cms.collections', [])),
            );
            foreach ($files as $file) {
                $this->flag('essential-new-visual-editor', $file,
                    'new visual editor in essential requires a per-file essential-editor-justification comment and review',
                    'docs/concepts/admin-profiles.md — New visual editors in Essential');
            }
        } catch (ProcessFailedException $exception) {
            $this->flag('essential-editor-git-scope', 'git',
                'cannot determine new editor files; verify the git repository/ref before retrying',
                'docs/concepts/admin-profiles.md — New visual editors in Essential');
        }
    }

    /** Content-managed images come from the media library, not from bundled asset paths. */
    private function templatesDoNotHardcodeImages(?array $scope): void
    {
        if (! $this->touches($scope, 'resources/views/templates')) {
            return;
        }

        foreach (File::allFiles(resource_path('views/templates')) as $file) {
            $relative = Str::after($file->getPathname(), base_path().'/');

            foreach (file($file->getPathname()) as $index => $line) {
                if (preg_match('/<img[^>]+src\s*=\s*"(?!\{\{)/i', $line)
                    || preg_match("/asset\(\s*'(images|img)\//i", $line)) {
                    $this->flag(
                        'template-hardcoded-image',
                        $relative.':'.($index + 1),
                        'content-managed image rendered from a literal path',
                        'docs/content/images.md — Rendering Contract'
                    );
                }
            }
        }
    }

    /** Content must never carry an absolute URL of this installation. */
    private function seedersDoNotStoreAbsoluteUrls(?array $scope): void
    {
        if (! $this->touches($scope, 'database/seeders')) {
            return;
        }

        foreach (File::allFiles(database_path('seeders')) as $file) {
            $relative = Str::after($file->getPathname(), base_path().'/');

            foreach (file($file->getPathname()) as $index => $line) {
                if (preg_match('#[\'"]https?://(localhost|127\.0\.0\.1)#i', $line)) {
                    $this->flag(
                        'seeder-absolute-url',
                        $relative.':'.($index + 1),
                        'absolute URL of this site stored in seeded content',
                        'docs/concepts/content-model.md — Sections'
                    );
                }
            }
        }
    }

    /** VERSION matches the newest versioned changelog entry. */
    private function versionMatchesChangelog(?array $scope): void
    {
        if (! $this->touches($scope, 'CHANGELOG.md', 'VERSION')) {
            return;
        }

        $version = trim((string) @file_get_contents(base_path('VERSION')));
        $changelog = (string) @file_get_contents(base_path('CHANGELOG.md'));

        if (! preg_match('/^## (\d+\.\d+\.\d+)/m', $changelog, $matches)) {
            return;
        }

        if ($version !== $matches[1]) {
            $this->flag(
                'version-changelog-sync',
                'VERSION',
                "VERSION is {$version} but the newest changelog entry is {$matches[1]}",
                'changelog-version skill'
            );
        }
    }

    /** @return list<string> */
    private function adminLocales(): array
    {
        return array_values(array_filter(
            array_map(fn (string $dir): string => basename($dir), File::directories(lang_path())),
            fn (string $locale): bool => File::exists(lang_path("{$locale}/admin.php"))
        ));
    }

    /** @return array<string, mixed> flattened dot-notation keys */
    private function translations(string $locale): array
    {
        $path = lang_path("{$locale}/admin.php");

        return File::exists($path) ? $this->flatten(require $path) : [];
    }

    /**
     * Flatten nested translation arrays to dot keys, keeping list values as leaves.
     *
     * @return array<string, mixed>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $flat = [];

        foreach ($array as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += $this->flatten($value, $full);

                continue;
            }

            $flat[$full] = $value;
        }

        return $flat;
    }
}
