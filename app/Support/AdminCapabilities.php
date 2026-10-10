<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Post;

/** Deployment capabilities only. Never authorizes an actor or a credential. */
final class AdminCapabilities
{
    public function profile(): string
    {
        $profile = config('cms.admin_profile', 'full');
        if (! in_array($profile, ['full', 'essential'], true)) {
            throw new \LogicException('Invalid cms.admin_profile: '.json_encode($profile).'. Expected full or essential in config/cms.php.');
        }

        return $profile;
    }

    public function liveEditor(): bool
    {
        return $this->profile() === 'full';
    }

    public function requiresSectionEditor(array $template, string $section): bool
    {
        if (($template['visual_editor'] ?? true) === false) {
            return false;
        }
        foreach (array_keys($template['schema'] ?? []) as $path) {
            $prefix = "sections.$section.";
            if (str_starts_with($path, $prefix) && ! in_array(substr($path, strlen($prefix)), ['heading', 'body', 'is_visible'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Mirrors the Live Editor's shared section registry: first registered existing partial wins. */
    public function sectionEditor(string $section): ?string
    {
        foreach (config('cms.templates', []) as $key => $template) {
            if (in_array($section, $template['sections'] ?? [], true)
                && is_file(resource_path("views/admin/pages/sections/{$key}_{$section}.blade.php"))) {
                return "admin.pages.sections.{$key}_{$section}";
            }
        }

        return null;
    }

    public function contentUrl(object $model, ?string $returnUrl = null): string
    {
        if ($model instanceof Page && ! $this->pageVisualEditor($model->template_key)) {
            return route('admin.editorial.index', ['pages', $model->getKey()]);
        }
        if (! $this->liveEditor() && ! in_array($model->getTable(), ['pages', 'posts'], true)) {
            foreach (config('cms.collections', []) as $type => $collection) {
                if ($model instanceof $collection['model']) {
                    return route('admin.content.show', [$type, $model->getKey()]);
                }
            }
        }

        return $this->liveEditor()
            ? route('admin.'.$model->getTable().'.edit', [$model, 'return' => $returnUrl])
            : route('admin.editorial.index', [$model->getTable(), $model->getKey()]);
    }

    public function pageVisualEditor(string $template): bool
    {
        return $this->liveEditor() && (config("cms.templates.$template.visual_editor", true) !== false);
    }

    public function publicationState(object $model): string
    {
        if ($model->status !== 'published') {
            return 'draft';
        }
        if ($model->expires_at && $model->expires_at->lte(now())) {
            return 'expired';
        }

        return $model->isPublished() ? 'published' : 'scheduled';
    }

    /** The same secret-free deployment manifest for CLI and MCP. */
    public function discovery(): array
    {
        $templates = [];
        foreach (config('cms.templates', []) as $key => $template) {
            $sections = [];
            foreach ($template['sections'] ?? [] as $section) {
                $specialized = $this->requiresSectionEditor($template, $section);
                $partial = $this->sectionEditor($section);
                $sections[$section] = [
                    'specialized_editor_required' => $this->liveEditor() && $specialized,
                    'visual_editor_available' => $this->pageVisualEditor($key) && (! $specialized || $partial !== null),
                ];
            }
            $templates[$key] = ['sections' => $sections, 'schema' => $template['schema'] ?? []];
        }

        return [
            'admin_profile' => $this->profile(),
            'stability' => 'stable',
            'capabilities' => ['public_site' => true, 'mcp' => true, 'identity' => true, 'custom_code' => true, 'live_editor' => $this->liveEditor(), 'specialized_editor_delivery' => $this->liveEditor()],
            'authorization' => 'Independent of profile: account role and credential scope apply to every operation.',
            'block_formats' => ['structured', 'html', 'interactive'],
            'composition' => ['guide' => 'docs/content/composition.md', 'components' => config('cms.composition.components', []), 'html_rules' => app(CompositionHtmlSanitizer::class)->discovery()],
            'templates' => $templates,
            'registered_collections' => collect(config('cms.collections', []))->map(fn ($collection) => ['model' => class_basename($collection['model']), 'mcp_operations' => $collection['model'] === Post::class ? 'posts' : null])->all(),
            'schema_discovery' => ['tool' => 'cms_schema', 'content_types' => ['pages', 'posts']],
            'locales_discovery' => 'locales_list',
            'custom_code' => ['tools' => ['custom_code_list', 'custom_code_get', 'custom_code_save', 'custom_code_activate', 'custom_code_deactivate', 'custom_code_evaluate', 'custom_code_destinations'], 'credential_scope' => 'custom-code', 'account_permission' => 'can_manage_custom_code', 'save_activates' => false, 'exact_version_activation' => true, 'exclusions_prevail' => true],
            'content_rules' => ['media_by_id', 'root_relative_internal_urls', 'published_only_public_queries', 'forms_defined_in_code'],
            'profile_guide' => 'docs/concepts/admin-profiles.md',
        ];
    }
}
