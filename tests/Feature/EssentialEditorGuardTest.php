<?php

namespace Tests\Feature;

use App\Support\EssentialEditorGuard;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EssentialEditorGuardTest extends TestCase
{
    private string $repository;

    private string $editor = 'resources/views/admin/pages/sections/example_cards.blade.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = sys_get_temp_dir().'/essential-editor-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->repository);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'guard@example.test');
        $this->git('config', 'user.name', 'Guard Test');
        $this->git('config', 'core.hooksPath', '/dev/null');
        $this->git('config', 'commit.gpgSign', 'false');
        $this->git('commit', '-qm', 'baseline', '--allow-empty');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repository);
        parent::tearDown();
    }

    public function test_essential_rejects_untracked_and_staged_new_editors_but_full_allows_them(): void
    {
        $this->writeEditor('<div></div>');
        $this->assertSame([$this->editor], $this->check());
        $this->assertSame([], $this->check(staged: true));
        $this->git('add', '.');
        $this->assertSame([$this->editor], $this->check(staged: true));
        $this->assertSame([], $this->check(profile: 'full', staged: true));
    }

    public function test_justification_must_be_explicit_and_staged_with_the_file(): void
    {
        $this->writeEditor('<div></div>');
        $this->git('add', '.');
        $this->writeEditor('{{-- essential-editor-justification: Required operational credential rotation workflow. --}}');
        $this->assertSame([], $this->check());
        $this->assertSame([$this->editor], $this->check(staged: true));
        $this->git('add', '.');
        $this->assertSame([], $this->check(staged: true));
        $this->writeEditor('{{-- essential-editor-justification: approved --}}');
        $this->assertSame([$this->editor], $this->check());
    }

    public function test_existing_editor_edits_pass_and_since_detects_committed_additions(): void
    {
        $baseline = trim($this->git('rev-parse', 'HEAD'));
        $this->writeEditor('<div></div>');
        $this->git('add', '.');
        $this->git('commit', '-qm', 'editor');
        $this->assertSame([], $this->check());
        $this->assertSame([$this->editor], $this->check(since: $baseline));
        $this->writeEditor('<div>Changed</div>');
        $this->git('add', '.');
        $this->assertSame([], $this->check(staged: true));
        $this->assertSame([], $this->check(since: 'HEAD'));
    }

    public function test_operational_views_outside_editor_paths_are_allowed(): void
    {
        $this->editor = 'resources/views/admin/users/create.blade.php';
        $this->writeEditor('<div></div>');
        $this->git('add', '.');
        $this->assertSame([], $this->check(staged: true));
    }

    public function test_new_admin_forms_need_a_reason_and_renames_are_new_paths(): void
    {
        $this->editor = 'resources/views/admin/products/_form.blade.php';
        $this->writeEditor('<div></div>');
        $this->git('add', '.');
        $this->assertSame([$this->editor], $this->check(staged: true));
        $this->git('commit', '-qm', 'inherited form');
        $renamed = 'resources/views/admin/products/edit.blade.php';
        $this->git('mv', $this->editor, $renamed);
        $this->assertSame([$renamed], $this->check(staged: true));
    }

    public function test_invalid_since_ref_fails_instead_of_silently_skipping(): void
    {
        $this->expectException(ProcessFailedException::class);
        $this->check(since: 'missing-ref');
    }

    public function test_missing_git_metadata_fails_instead_of_silently_skipping(): void
    {
        File::deleteDirectory($this->repository.'/.git');
        $this->expectException(ProcessFailedException::class);
        $this->check();
    }

    private function writeEditor(string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->repository.'/'.$this->editor));
        File::put($this->repository.'/'.$this->editor, $contents);
    }

    private function check(string $profile = 'essential', bool $staged = false, ?string $since = null): array
    {
        return (new EssentialEditorGuard)->violations($this->repository, $profile, $staged, $since, ['products']);
    }

    private function git(string ...$arguments): string
    {
        return (new Process(['git', ...$arguments], $this->repository))->mustRun()->getOutput();
    }
}
