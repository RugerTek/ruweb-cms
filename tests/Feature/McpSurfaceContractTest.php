<?php

namespace Tests\Feature;

use App\Http\Controllers\McpServerController;
use App\Http\Middleware\EnforceMcpScopes;
use App\Mcp\ToolRegistry;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The mechanical half of docs/operations/mcp-surface.md: a feature cannot ship half-reachable by agents.
 * Each failure message says which file to complete. Every check runs with the optional shop catalog off and on,
 * because the catalog registers its own collection and tools.
 */
class McpSurfaceContractTest extends TestCase
{
    use RefreshDatabase;

    public static function shopModes(): array
    {
        return ['catalog off' => ['off'], 'catalog on' => ['catalog']];
    }

    private function tools(): array
    {
        return array_column(ToolRegistry::all(), 'name');
    }

    private function readTools(): array
    {
        return EnforceMcpScopes::reads();
    }

    private function isRead(string $tool): bool
    {
        return ! str_starts_with($tool, 'custom_code_')
            && (str_starts_with($tool, 'cms_') || preg_match('/_(list|get|versions|version|diff|reviews|read_field|translation_changes|usages)$/', $tool) === 1);
    }

    #[DataProvider('shopModes')]
    public function test_every_tool_has_a_dispatcher_handler(string $shopMode): void
    {
        $this->useShop($shopMode);
        $resolve = new \ReflectionMethod(McpServerController::class, 'resolveHandler');
        $server = app(McpServerController::class);
        $missing = array_values(array_filter($this->tools(), fn ($tool) => $resolve->invoke($server, $tool) === null));

        $this->assertSame([], $missing, 'Add these tools to McpServerController::resolveHandler(): '.implode(', ', $missing));
    }

    #[DataProvider('shopModes')]
    public function test_reads_are_available_to_restricted_credentials_and_writes_are_not(string $shopMode): void
    {
        $this->useShop($shopMode);
        $unclassified = array_values(array_filter($this->tools(), fn ($tool) => $this->isRead($tool) && ! in_array($tool, $this->readTools(), true)));
        $writesAsReads = array_values(array_filter($this->readTools(), fn ($tool) => ! $this->isRead($tool)));

        $this->assertSame([], $unclassified, 'Add these reads to EnforceMcpScopes::READ (or reads() for an optional module): '.implode(', ', $unclassified));
        $this->assertSame([], $writesAsReads, 'These entries of EnforceMcpScopes::reads() are not reads: '.implode(', ', $writesAsReads));
        $this->assertSame([], array_values(array_diff($this->readTools(), $this->tools())), 'EnforceMcpScopes::reads() lists tools that do not exist.');
    }

    #[DataProvider('shopModes')]
    public function test_every_tool_is_documented_in_the_mcp_guide(string $shopMode): void
    {
        $this->useShop($shopMode);
        $doc = (string) file_get_contents(base_path('docs/operations/mcp.md'));
        $undocumented = array_values(array_filter($this->tools(), fn ($tool) => ! str_contains($doc, '`'.$tool.'`')));

        $this->assertSame([], $undocumented, 'Add these tools to the table in docs/operations/mcp.md: '.implode(', ', $undocumented));
    }

    #[DataProvider('shopModes')]
    public function test_every_registered_collection_is_operable_through_mcp(string $shopMode): void
    {
        $this->useShop($shopMode);
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

    #[DataProvider('shopModes')]
    public function test_tool_names_follow_the_resource_verb_convention(string $shopMode): void
    {
        $this->useShop($shopMode);
        $bad = array_values(array_filter($this->tools(), fn ($tool) => ! preg_match('/^[a-z]+(?:_[a-z]+)+$/', $tool)));

        $this->assertSame([], $bad, 'Tool names are <resource>_<verb> in snake_case: '.implode(', ', $bad));
    }
}
