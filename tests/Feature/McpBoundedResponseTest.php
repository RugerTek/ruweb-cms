<?php

namespace Tests\Feature;

use App\Mcp\ContentResponse;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpBoundedResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_editorial_strings_are_explicitly_clipped_and_readable_in_ranges(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $key = $actor->mcpApiKeys()->create(['name' => 'Test', 'key_hash' => hash('sha256', 'bounded-token'), 'scope' => 'read-only']);
        $this->withHeader('Authorization', 'Bearer bounded-token');
        $copy = str_repeat('á', 12000);
        $post = Post::create(['title' => 'Long', 'slug' => 'long', 'status' => 'draft', 'content' => $copy]);
        $this->getJson('/mcp/posts/'.$post->id)->assertOk()->assertJsonPath('data.truncated_fields.0', 'content');
        $chunk = $this->getJson('/mcp/content/posts/'.$post->id.'/field?field=content&offset=8192&length=8192')->assertOk()->assertJsonPath('data.total_characters', 12000)->assertJsonPath('data.has_more', false)->json('data.value');
        $this->assertSame(mb_substr($copy, 8192, 8192), $chunk);
        $this->assertSame($copy, $post->fresh()->content);
        $this->getJson('/mcp/posts/'.$post->id.'?fields[]=title')->assertOk()->assertJsonMissingPath('data.content')->assertJsonPath('data.title', 'Long');
        $this->getJson('/mcp/content/posts/'.$post->id.'/field?field=created_by')->assertUnprocessable();
        $bounded = ContentResponse::bounded(['before' => $copy, 'after' => $copy, 'hash' => str_repeat('a', 64)]);
        $this->assertContains('before', $bounded['truncated_fields']);
        $this->assertSame(str_repeat('a', 64), $bounded['hash']);
    }

    public function test_adversarial_json_keys_and_arrays_stay_bounded_without_clipping_revision(): void
    {
        $key = str_repeat('a', 1000000);
        $revision = str_repeat('b', 64);
        $data = ContentResponse::bounded(['content_json' => [$key => 'value', 'items' => array_fill(0, 200, str_repeat('c', 8192))], 'revision' => $revision]);
        $this->assertLessThan(150000, strlen(json_encode($data)));
        $this->assertSame($revision, $data['revision']);
        $this->assertNotEmpty($data['truncated_fields']);
        $this->assertLessThan(501, max(array_map('strlen', $data['truncated_fields'])));
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->mcpApiKeys()->create(['name' => 'Array', 'key_hash' => hash('sha256', 'array-token'), 'scope' => 'read-only']);
        $this->withHeader('Authorization', 'Bearer array-token');
        $post = Post::create(['title' => 'Array', 'slug' => 'array', 'status' => 'draft', 'meta_json' => ['items' => array_fill(0, 200, 'safe')]]);
        $this->getJson('/mcp/content/posts/'.$post->id.'/field?field=meta_json.items&offset=100&length=100')->assertOk()->assertJsonPath('data.total_items', 200)->assertJsonPath('data.has_more', false)->assertJsonCount(100, 'data.value');
        $this->assertCount(200, $post->fresh()->meta_json['items']);
    }
}
