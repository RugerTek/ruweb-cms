<?php

namespace Tests\Feature;

use App\Models\ContentRevision;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContentRevisionLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_writes_append_revisions_with_chained_parents(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        ContentRevision::query()->delete();

        $page = Page::query()->create([
            'locale' => 'es', 'title' => 'A', 'slug' => 'a', 'template_key' => 'default',
            'content_json' => [], 'status' => 'draft', 'created_by' => $admin->id,
        ]);
        $page->update(['title' => 'B']);

        $revisions = ContentRevision::query()->orderBy('id')->get();

        $this->assertCount(2, $revisions);
        $this->assertSame('created', $revisions[0]->action);
        $this->assertSame('updated', $revisions[1]->action);
        $this->assertSame(Page::class, $revisions[1]->entity_type);
        $this->assertSame($page->id, $revisions[1]->entity_id);
        $this->assertSame($admin->id, $revisions[1]->user_id);
        $this->assertSame($revisions[0]->hash, $revisions[1]->parent_hash);
    }

    public function test_menu_item_bursts_coalesce_into_one_revision_per_menu(): void
    {
        ContentRevision::query()->delete();
        $menu = Menu::query()->create(['locale' => 'es', 'name' => 'Main', 'slug' => 'main', 'location' => 'header']);

        // Items record against their menu, and repeats within a request coalesce.
        foreach (range(1, 5) as $i) {
            MenuItem::query()->create(['menu_id' => $menu->id, 'label' => "Item {$i}", 'type' => 'custom_link', 'url' => '/x', 'target' => '_self', 'order' => $i]);
        }

        $this->assertSame(1, ContentRevision::query()->where('entity_type', Menu::class)->where('entity_id', $menu->id)->where('action', 'created')->count());
    }

    public function test_without_recording_suppresses_the_ledger(): void
    {
        ContentRevision::query()->delete();

        app(ContentRevisionLedger::class)->withoutRecording(function (): void {
            Menu::query()->create(['locale' => 'es', 'name' => 'Quiet', 'slug' => 'quiet', 'location' => 'footer']);
        });

        $this->assertSame(0, ContentRevision::query()->count());
    }

    public function test_prune_keeps_a_minimum_and_removes_only_old_rows(): void
    {
        ContentRevision::query()->delete();
        $ledger = app(ContentRevisionLedger::class);

        foreach (range(1, 6) as $i) {
            $ledger->record('X', $i, 'created');
        }
        ContentRevision::query()->where('id', '<=', 4)->update(['created_at' => Carbon::now()->subDays(200)]);

        $this->assertSame(0, $ledger->prune(90, 10), 'Nothing removed while under the minimum.');
        $this->assertSame(1, $ledger->prune(90, 5), 'Only the old row outside the newest five goes away.');
        $this->assertSame(3, $ledger->prune(90, 2), 'Old rows beyond the newest two go away.');
        $this->assertSame(2, ContentRevision::query()->count());
    }
}
