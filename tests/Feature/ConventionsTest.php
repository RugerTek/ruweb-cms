<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The repository must satisfy its own documented rules, so a local `--no-verify` cannot
 * land what the pre-commit hook would have caught.
 */
class ConventionsTest extends TestCase
{
    public function test_the_repository_satisfies_its_documented_conventions(): void
    {
        $exitCode = Artisan::call('cms:check-conventions');

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
