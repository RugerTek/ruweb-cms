<?php

namespace Tests\Support;

use App\Support\EssentialEditorGuard;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** Git contracts are exercised independently of the checkout's own metadata. */
final class GitRepositoryFixture
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/cms-git-fixture-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->root);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'fixture@example.test');
        $this->git('config', 'user.name', 'Repository Fixture');
        $this->git('config', 'core.hooksPath', '/dev/null');
        $this->git('config', 'commit.gpgSign', 'false');
    }

    public function copy(string $source, string $relative): void
    {
        $destination = $this->root.'/'.$relative;
        File::ensureDirectoryExists(dirname($destination));
        if (is_dir($source)) {
            File::copyDirectory($source, $destination);
        } else {
            File::copy($source, $destination);
        }
    }

    public function baseline(): void
    {
        $this->git('add', '.');
        $this->git('commit', '-qm', 'fixture baseline', '--allow-empty');
    }

    public function git(string ...$arguments): string
    {
        return (new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput();
    }

    /** The command still scans real source; only Git comparisons use the fixture. */
    public function editorGuard(): EssentialEditorGuard
    {
        return new class($this->root) extends EssentialEditorGuard
        {
            public function __construct(private readonly string $fixtureRoot) {}

            public function violations(string $root, string $profile, bool $staged = false, ?string $since = null, array $collections = []): array
            {
                return parent::violations($this->fixtureRoot, $profile, $staged, $since, $collections);
            }
        };
    }

    public function delete(): void
    {
        File::deleteDirectory($this->root);
    }
}
