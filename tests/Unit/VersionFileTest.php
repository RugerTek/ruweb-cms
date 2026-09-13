<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class VersionFileTest extends TestCase
{
    public function test_version_file_matches_latest_changelog_entry(): void
    {
        $version = trim((string) file_get_contents(dirname(__DIR__, 2).'/VERSION'));
        $changelog = (string) file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'VERSION must be a bare semver.');
        $this->assertSame(1, preg_match('/^## (\d+\.\d+\.\d+) - /m', $changelog, $matches), 'CHANGELOG.md has no versioned entry.');
        $this->assertSame($matches[1], $version, 'VERSION must match the newest versioned section in CHANGELOG.md.');
    }
}
