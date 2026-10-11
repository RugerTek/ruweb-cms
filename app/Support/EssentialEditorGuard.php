<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/** Checks additions only: inherited Full editors remain valid in Essential. */
class EssentialEditorGuard
{
    /** @return list<string> Unjustified new visual editor paths. */
    public function violations(string $root, string $profile, bool $staged = false, ?string $since = null, array $collections = []): array
    {
        if ($profile !== 'essential') {
            return [];
        }

        $diff = ['git', 'diff', '--no-renames', '--name-only', '-z', '--diff-filter=A'];
        if ($staged) {
            $diff[] = '--cached';
        } else {
            $diff[] = $since ?? 'HEAD';
        }
        $diff[] = '--';
        $paths = $this->git($root, $diff);
        if (! $staged && $since === null) {
            $paths .= $this->git($root, ['git', 'ls-files', '--others', '--exclude-standard', '-z']);
        }

        $violations = [];
        foreach (array_unique(array_filter(explode("\0", $paths))) as $path) {
            if (! $this->isVisualEditor($path, $collections)) {
                continue;
            }
            $contents = $staged
                ? $this->git($root, ['git', 'show', ':'.$path])
                : (string) file_get_contents($root.'/'.$path);
            // The reason is per file, intentionally visible to the judgment review.
            if (! preg_match('/^\s*\{\{-- essential-editor-justification: ([^\r\n]+) --\}\}\s*$/m', $contents, $match)
                || strlen(trim($match[1])) < 20) {
                $violations[] = $path;
            }
        }

        return $violations;
    }

    private function isVisualEditor(string $path, array $collections): bool
    {
        return str_ends_with($path, '.blade.php') && (
            str_starts_with($path, 'resources/views/admin/pages/sections/')
            || (preg_match('#^resources/views/admin/([^/]+)/(?:_form|create|edit)\.blade\.php$#', $path, $match) === 1
                && in_array($match[1], ['pages', 'posts', ...$collections], true))
        );
    }

    private function git(string $root, array $arguments): string
    {
        $process = new Process($arguments, $root);
        $process->mustRun();

        return $process->getOutput();
    }
}
