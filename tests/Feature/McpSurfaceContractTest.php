<?php

namespace Tests\Feature;

use App\Http\Controllers\McpServerController;
use App\Http\Middleware\EnforceMcpScopes;
use App\Mcp\ToolRegistry;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mechanical half of docs/operations/mcp-surface.md: a feature cannot ship half-reachable by agents.
 * Each failure message says which file to complete.
 */
class McpSurfaceContractTest extends TestCase
{
    use RefreshDatabase;

    private function tools(): array
    {
        return array_column(ToolRegistry::all(), 'name');
    }

    private function readTools(): array
    {
        return (new \ReflectionClassConstant(EnforceMcpScopes::class, 'READ'))->getValue();
    }

    private function isRead(string $tool): bool
    {
        return ! str_starts_with($tool, 'custom_code_')
            && (str_starts_with($tool, 'cms_') || preg_match('/_(list|get|versions|version|diff|reviews|read_field|translation_changes|usages)$/', $tool) === 1);
    }

    public function test_every_tool_has_a_dispatcher_handler(): void
    {
        $resolve = new \ReflectionMethod(McpServerController::class, 'resolveHandler');
        $server = app(McpServerController::class);
        $missing = array_values(array_filter($this->tools(), fn ($tool) => $resolve->invoke($server, $tool) === null));

        $this->assertSame([], $missing, 'Add these tools to McpServerController::resolveHandler(): '.implode(', ', $missing));
    }

    public function test_reads_are_available_to_restricted_credentials_and_writes_are_not(): void
    {
        $unclassified = array_values(array_filter($this->tools(), fn ($tool) => $this->isRead($tool) && ! in_array($tool, $this->readTools(), true)));
        $writesAsReads = array_values(array_filter($this->readTools(), fn ($tool) => ! $this->isRead($tool)));

        $this->assertSame([], $unclassified, 'Add these reads to EnforceMcpScopes::READ: '.implode(', ', $unclassified));
        $this->assertSame([], $writesAsReads, 'These entries of EnforceMcpScopes::READ are not reads: '.implode(', ', $writesAsReads));
        $this->assertSame([], array_values(array_diff($this->readTools(), $this->tools())), 'EnforceMcpScopes::READ lists tools that do not exist.');
    }

    public function test_every_tool_is_documented_in_the_mcp_guide(): void
    {
        $doc = (string) file_get_contents(base_path('docs/operations/mcp.md'));
        $undocumented = array_values(array_filter($this->tools(), fn ($tool) => ! str_contains($doc, '`'.$tool.'`')));

        $this->assertSame([], $undocumented, 'Add these tools to the table in docs/operations/mcp.md: '.implode(', ', $undocumented));
    }

    public function test_every_registered_collection_is_operable_through_mcp(): void
    {
        foreach (config('cms.collections', []) as $key => $collection) {
            $prefix = $collection['mcp_operations'] ?? null;
            $this->assertIsString($prefix, "config/cms.php collections.{$key} needs 'mcp_operations' (docs/operations/mcp-surface.md#a-new-collection).");
            foreach (['list', 'get', 'create_draft', 'update_draft', 'update', 'publish', 'delete'] as $verb) {
                $this->assertContains($prefix.'_'.$verb, $this->tools(), "Collection {$key} is missing the {$prefix}_{$verb} tool.");
            }
        }
    }

    public function test_every_writable_setting_has_a_starter_value(): void
    {
        $missing = array_values(array_filter(config('cms.mcp.settings_writable_keys', []), fn ($key) => SettingDefaults::for($key) === null));

        $this->assertSame([], $missing, 'Add these writable settings to App\Support\SettingDefaults: '.implode(', ', $missing));
    }

    public function test_tool_names_follow_the_resource_verb_convention(): void
    {
        $bad = array_values(array_filter($this->tools(), fn ($tool) => ! preg_match('/^[a-z]+(?:_[a-z]+)+$/', $tool)));

        $this->assertSame([], $bad, 'Tool names are <resource>_<verb> in snake_case: '.implode(', ', $bad));
    }
}
