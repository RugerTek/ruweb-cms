<?php

namespace Tests\Feature;

use App\Http\Controllers\McpMediaController;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use App\Services\Media\MediaOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MediaOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        foreach (['es', 'en'] as $code) {
            Locale::create(['code' => $code, 'name' => $code, 'is_base' => $code === 'es', 'is_active' => true, 'is_public' => true]);
        }
        config(['cms.image_optimization.enabled' => false, 'cms.responsive_images.enabled' => false]);
    }

    private function request(User $actor, array $input = [], ?UploadedFile $file = null, string $credential = 'test-credential'): Request
    {
        $request = Request::create('/mcp/media/uploads', 'POST', $input, [], $file ? ['file' => $file] : [], ['HTTP_AUTHORIZATION' => 'Bearer '.$credential]);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    public function test_upload_ticket_retries_return_same_asset_and_changed_input_conflicts(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $controller = app(McpMediaController::class);
        $ticket = $controller->authorizeUpload($this->request($actor))->getData(true)['data']['ticket'];
        $file = fn () => UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z"/></svg>');
        $first = $controller->upload($this->request($actor, ['ticket' => $ticket], $file()))->getData(true);
        $second = $controller->upload($this->request($actor, ['ticket' => $ticket], $file()))->getData(true);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('media', 1);
        Storage::disk('public')->assertExists($first['data'][0]['path']);
        $this->expectException(HttpException::class);
        $controller->upload($this->request($actor, ['ticket' => $ticket, 'alt' => 'Different'], $file()));
    }

    public function test_ticket_is_bound_to_credential_and_expires(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $controller = app(McpMediaController::class);
        $ticket = $controller->authorizeUpload($this->request($actor))->getData(true)['data']['ticket'];
        try {
            $controller->upload($this->request($actor, ['ticket' => $ticket], null, 'other-token'));
            $this->fail('Ticket accepted another credential.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->travel(11)->minutes();
        try {
            $controller->upload($this->request($actor, ['ticket' => $ticket]));
            $this->fail('Expired ticket accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('media', 0);
    }

    public function test_metadata_patch_preserves_other_fields_and_rejects_stale_base(): void
    {
        $actor = User::factory()->create(['role' => 'editor']);
        $ops = app(MediaOperations::class);
        $media = $ops->upload($actor, ['file' => UploadedFile::fake()->image('photo.jpg')])[0];
        $media = $ops->update($actor, $media, ['metadata' => ['en' => ['alt' => 'Old alt', 'title' => 'Preserved title']]]);
        $base = $ops->fingerprint($media);
        $media = $ops->update($actor, $media, ['locale' => 'en', 'alt' => 'New alt'], $base);
        $this->assertSame('Preserved title', $media->localizedTitle('en'));
        $this->assertSame('New alt', $media->localizedAlt('en'));
        $this->expectException(HttpException::class);
        $ops->update($actor, $media, ['locale' => 'en', 'alt' => 'Stale write'], $base);
    }

    public function test_delete_rollback_keeps_the_record_and_every_file(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ops = app(MediaOperations::class);
        $media = $ops->upload($actor, ['file' => UploadedFile::fake()->image('rollback.jpg')])[0];
        $files = Storage::disk('public')->allFiles();
        try {
            DB::transaction(function () use ($ops, $actor, $media) {
                $ops->delete($actor, $media);
                throw new \RuntimeException('Failure before commit');
            });
        } catch (\RuntimeException) {
            $this->assertModelExists($media);
            $this->assertSame($files, Storage::disk('public')->allFiles());
        }
    }

    public function test_upload_rollback_cleans_files_for_all_batch_members(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        try {
            DB::transaction(function () use ($actor) {
                app(MediaOperations::class)->upload($actor, ['files' => [
                    UploadedFile::fake()->image('first.jpg'),
                    UploadedFile::fake()->image('second.jpg'),
                ]]);
                throw new \RuntimeException('Receipt could not commit');
            });
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('media', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_second_batch_persistence_failure_rolls_back_first_asset_and_files(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $event = 'eloquent.creating: '.Media::class;
        Media::creating(function (Media $media) {
            if ($media->original_filename === 'broken.jpg') {
                throw new \RuntimeException('Simulated persistence failure');
            }
        });
        try {
            app(MediaOperations::class)->upload($actor, ['files' => [
                UploadedFile::fake()->image('first.jpg'),
                UploadedFile::fake()->image('broken.jpg'),
            ]]);
            $this->fail('Failed batch partially committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated persistence failure', $exception->getMessage());
            $this->assertDatabaseCount('media', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        } finally {
            Media::getEventDispatcher()->forget($event);
        }
    }

    public function test_all_uploads_are_validated_before_any_asset_is_stored(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        try {
            app(MediaOperations::class)->upload($actor, ['files' => [
                UploadedFile::fake()->image('safe.jpg'),
                UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            ]]);
            $this->fail('Unsafe batch accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('media', 0);
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_multipart_service_reuses_usage_protection(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ops = app(MediaOperations::class);
        $media = $ops->upload($actor, ['file' => UploadedFile::fake()->image('photo.jpg')])[0];
        Page::create(['title' => 'Draft hero', 'slug' => 'draft-hero', 'template_key' => 'home', 'status' => 'draft', 'locale' => 'es', 'content_json' => ['sections' => ['hero' => ['image_id' => $media->id]]]]);
        $this->assertNotEmpty($ops->usages($actor, $media));
        try {
            $ops->delete($actor, $media);
            $this->fail('Referenced file deleted.');
        } catch (ValidationException) {
            $this->assertModelExists($media);
            Storage::disk('public')->assertExists($media->path);
        }
    }

    public function test_usage_responses_are_paginated_without_reducing_deletion_protection(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->mcpApiKeys()->create(['name' => 'Usage pagination', 'key_hash' => hash('sha256', 'usage-pagination'), 'scope' => 'full']);
        $this->withHeader('Authorization', 'Bearer usage-pagination');
        $media = app(MediaOperations::class)->upload($actor, ['file' => UploadedFile::fake()->image('usage.png')])[0];
        for ($index = 0; $index < 3; $index++) {
            $page = Page::create(['title' => 'Usage '.$index, 'slug' => 'usage-'.$index, 'template_key' => 'default', 'status' => 'draft']);
            $page->attachMedia($media->id, 'featured_image');
        }
        $first = $this->getJson('/mcp/media/'.$media->id.'/usages?per_page=1&page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)->json();
        $this->assertGreaterThan(1, $first['meta']['total']);
        $response = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'media_usages', 'arguments' => ['media_id' => $media->id, 'page' => 2, 'per_page' => 1]]])->assertOk()->assertJsonPath('result.isError', false);
        $second = json_decode($response->json('result.content.0.text'), true);
        $this->assertCount(1, $second['data']);
        $this->assertSame($first['meta']['total'], $second['meta']['total']);
        $this->assertNotSame($first['data'][0], $second['data'][0]);
        $this->getJson('/mcp/media/'.$media->id.'/usages?per_page=101')->assertUnprocessable();
        $this->deleteJson('/mcp/media/'.$media->id)->assertUnprocessable();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }
}
