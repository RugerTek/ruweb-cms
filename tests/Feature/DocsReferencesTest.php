<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Tests\Support\GitRepositoryFixture;

/**
 * Docs are agent-facing contracts: a link or a path that no longer resolves sends an
 * agent down a dead end. This test is what keeps the next rename honest.
 */
class DocsReferencesTest extends TestCase
{
    public function test_every_markdown_link_in_the_docs_resolves(): void
    {
        $broken = [];

        foreach ($this->markdownFiles() as $file) {
            $directory = dirname($file->getRealPath());

            preg_match_all('/\]\(([^)]+)\)/', (string) file_get_contents($file->getRealPath()), $matches);

            foreach ($matches[1] as $link) {
                if (Str::startsWith($link, ['http://', 'https://', '#', 'mailto:'])) {
                    continue;
                }

                $target = realpath($directory.'/'.Str::before($link, '#'));

                if ($target === false) {
                    $broken[] = $this->relative($file->getRealPath()).' → '.$link;
                }
            }
        }

        $this->assertSame([], $broken, "Broken documentation links:\n".implode("\n", $broken));
    }

    public function test_repository_paths_mentioned_in_the_docs_exist(): void
    {
        $missing = [];

        foreach ($this->markdownFiles() as $file) {
            preg_match_all('/`([a-z][a-zA-Z0-9_\-\/\.]+\.(?:php|js|md|json|blade\.php))`/', (string) file_get_contents($file->getRealPath()), $matches);

            foreach ($matches[1] as $path) {
                if (! Str::startsWith($path, ['app/', 'config/', 'database/', 'resources/', 'routes/', 'tests/', 'lang/', 'docs/', 'public/'])) {
                    continue;
                }

                if (! file_exists(base_path($path))) {
                    $missing[] = $this->relative($file->getRealPath()).' → '.$path;
                }
            }
        }

        $this->assertSame([], $missing, "Documented paths that do not exist:\n".implode("\n", $missing));
    }

    public function test_every_skill_declares_a_usable_name_and_description(): void
    {
        $problems = [];

        foreach (Finder::create()->files()->in(base_path('.agents/skills'))->name('SKILL.md') as $file) {
            $slug = basename(dirname($file->getRealPath()));
            $contents = (string) file_get_contents($file->getRealPath());

            if (! preg_match('/^---\n(.*?)\n---\n/s', $contents, $frontmatter)) {
                $problems[] = "{$slug}: missing YAML frontmatter";

                continue;
            }

            if (! preg_match('/^name:\s*(\S+)\s*$/m', $frontmatter[1], $name) || $name[1] !== $slug) {
                $problems[] = "{$slug}: the name field must match the directory name";
            }

            if (! preg_match('/^description:\s*(.+)$/m', $frontmatter[1], $description)) {
                $problems[] = "{$slug}: missing description";

                continue;
            }

            if (Str::length(trim($description[1])) > 1024) {
                $problems[] = "{$slug}: description longer than 1024 characters";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_agent_entry_point_is_reachable_under_both_names(): void
    {
        $this->assertFileExists(base_path('AGENTS.md'));
        $this->assertFileExists(base_path('CLAUDE.md'));
        $this->assertSame(
            file_get_contents(base_path('AGENTS.md')),
            file_get_contents(base_path('CLAUDE.md')),
            'CLAUDE.md must resolve to the same content as AGENTS.md.'
        );
    }

    public function test_both_agents_share_skills_and_ignore_rules_allow_tracking_the_link(): void
    {
        $this->assertDirectoryExists(base_path('.agents/skills'), 'Codex reads .agents/skills.');

        $link = base_path('.claude/skills');

        $this->assertTrue(is_link($link) || is_dir($link), 'Claude Code reads .claude/skills.');
        $this->assertSame(
            realpath(base_path('.agents/skills')),
            realpath($link),
            '.claude/skills must resolve to .agents/skills so both agents read one source.'
        );

        // A source archive has no index: verify the shipped link and its tracking eligibility
        // using the actual ignore rules in a private repository.
        $repository = new GitRepositoryFixture;
        try {
            $repository->copy(base_path('.gitignore'), '.gitignore');
            mkdir($repository->root.'/.claude');
            symlink('../.agents/skills', $repository->root.'/.claude/skills');
            $repository->git('add', '.');
            $this->assertSame('.claude/skills', trim($repository->git('ls-files', '--error-unmatch', '.claude/skills')),
                'Repository ignore rules must include the skills symlink in a fresh clone.');
        } finally {
            $repository->delete();
        }
    }

    /** @return iterable<SplFileInfo> */
    private function markdownFiles(): iterable
    {
        return Finder::create()
            ->files()
            ->in([base_path('docs'), base_path('.agents/skills')])
            ->name('*.md')
            ->append(Finder::create()->files()->in(base_path())->depth(0)->name('AGENTS.md'));
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path().'/');
    }
}
