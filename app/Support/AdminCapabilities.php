<?php

namespace App\Support;

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
        return $this->liveEditor()
            ? route('admin.'.$model->getTable().'.edit', [$model, 'return' => $returnUrl])
            : route('admin.editorial.index', [$model->getTable(), $model->getKey()]);
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
                    'visual_editor_available' => $this->liveEditor() && (! $specialized || $partial !== null),
                ];
            }
            $templates[$key] = ['sections' => $sections, 'schema' => $template['schema'] ?? []];
        }

        return [
            'admin_profile' => $this->profile(),
            'stability' => $this->liveEditor() ? 'stable' : 'experimental',
            'capabilities' => ['public_site' => true, 'mcp' => true, 'identity' => true, 'live_editor' => $this->liveEditor(), 'specialized_editor_delivery' => $this->liveEditor()],
            'authorization' => 'Independent of profile: account role and credential scope apply to every operation.',
            'block_formats' => ['structured'],
            'templates' => $templates,
            'content_rules' => ['media_by_id', 'root_relative_internal_urls', 'published_only_public_queries', 'forms_defined_in_code'],
            'profile_guide' => 'docs/concepts/admin-profiles.md',
        ];
    }
}
