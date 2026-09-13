<?php

namespace Tests\Feature;

use App\Console\Commands\CmsInstallCommand;
use Tests\TestCase;

class CmsInstallAssetCheckTest extends TestCase
{
    protected string $originalPublicPath;
    protected string $tempPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPublicPath = public_path();
        $this->tempPublicPath = sys_get_temp_dir() . '/ruweb-public-' . uniqid();
        mkdir($this->tempPublicPath . '/build', 0755, true);
        $this->app->usePublicPath($this->tempPublicPath);
    }

    protected function tearDown(): void
    {
        $this->app->usePublicPath($this->originalPublicPath);
        exec('rm -rf ' . escapeshellarg($this->tempPublicPath));

        parent::tearDown();
    }

    public function test_missing_vite_manifest_is_reported_as_not_built(): void
    {
        $this->assertFalse(app(CmsInstallCommand::class)->frontendAssetsAreBuilt());
    }

    public function test_vite_manifest_marks_the_assets_as_built(): void
    {
        file_put_contents($this->tempPublicPath . '/build/manifest.json', '{}');

        $this->assertTrue(app(CmsInstallCommand::class)->frontendAssetsAreBuilt());
    }

    public function test_manifest_inside_the_vite_directory_is_also_detected(): void
    {
        mkdir($this->tempPublicPath . '/build/.vite', 0755, true);
        file_put_contents($this->tempPublicPath . '/build/.vite/manifest.json', '{}');

        $this->assertTrue(app(CmsInstallCommand::class)->frontendAssetsAreBuilt());
    }
}
