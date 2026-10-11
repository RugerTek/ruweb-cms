<?php

namespace Tests\Feature;

use App\Support\EssentialEditorGuard;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\GitRepositoryFixture;
use Tests\TestCase;

/**
 * The repository must satisfy its own documented rules, so a local `--no-verify` cannot
 * land what the pre-commit hook would have caught.
 */
class ConventionsTest extends TestCase
{
    public function test_the_repository_satisfies_its_documented_conventions(): void
    {
        $repository = new GitRepositoryFixture;
        try {
            $repository->copy(resource_path('views/admin'), 'resources/views/admin');
            $repository->baseline();
            $this->app->instance(EssentialEditorGuard::class, $repository->editorGuard());
            $exitCode = Artisan::call('cms:check-conventions');
            $this->assertSame(0, $exitCode, Artisan::output());
        } finally {
            $repository->delete();
        }
    }
}
