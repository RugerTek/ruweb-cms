<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPostPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useAdminProfile('full');
    }

    public function test_editor_can_create_post(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $response = $this->actingAs($editor)->post(route('admin.posts.store'), [
            'title' => 'My Post',
            'slug' => 'my-post',
            'excerpt' => 'Short excerpt',
            'content' => 'Content body',
            'status' => 'draft',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('posts', [
            'title' => 'My Post',
            'slug' => 'my-post',
            'status' => 'draft',
        ]);
    }

    public function test_editor_cannot_delete_post(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $post = Post::create([
            'title' => 'Delete Me',
            'slug' => 'delete-me',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($editor)->delete(route('admin.posts.destroy', $post));

        $response->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_admin_can_delete_post(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $post = Post::create([
            'title' => 'Delete Me',
            'slug' => 'delete-me',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.posts.destroy', $post));

        $response->assertRedirect(route('admin.posts.index'));
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }
}
