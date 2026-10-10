<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Services\Pages\PageOperations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageOperationRetryTest extends TestCase
{
    // Retry needs a real root transaction; RefreshDatabase wraps every test in one.
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
    }

    public function test_transaction_retry_after_a_model_event_keeps_the_revision_ledger(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PageOperations::class);
        $page = $operations->save($actor, ['title' => 'Retry', 'slug' => 'retry', 'template_key' => 'default'], draftOnly: true);
        $attempts = 0;
        Page::saved(function (Page $saved) use ($page, &$attempts) {
            if ($saved->id === $page->id && $saved->status === 'published' && ++$attempts === 1) {
                throw new \RuntimeException('Deadlock found when trying to get lock');
            }
        });
        $operations->publish($actor, $page);
        $this->assertSame(2, $attempts);
        $this->assertSame('published', $page->fresh()->status);
        $this->assertSame(1, DB::table('content_revisions')->where('entity_type', Page::class)->where('entity_id', $page->id)->where('action', 'updated')->count());
        $this->assertSame($actor->id, DB::table('content_revisions')->where('entity_type', Page::class)->where('entity_id', $page->id)->where('action', 'updated')->value('user_id'));
    }
}
