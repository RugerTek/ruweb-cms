<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\Media\FeedImage;
use App\Services\Media\MediaOperations;
use App\Support\SiteUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** JPEG derivatives of WebP media for catalog feeds (PLAN.md §4.8, stage 2, track 2A). */
class FeedImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }

        Storage::fake('public');
    }

    public function test_webp_becomes_a_valid_jpeg_with_an_absolute_url_and_is_generated_once(): void
    {
        $media = $this->webpMedia('foto.webp', 800, 600);

        $url = app(FeedImage::class)->jpegUrl($media);

        $files = Storage::disk('public')->files('feed');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^feed/'.$media->id.'-[0-9a-f]{16}\.jpg$#', $files[0]);
        $this->assertSame(SiteUrl::origin().Storage::disk('public')->url($files[0]), $url);
        $this->assertMatchesRegularExpression('#^https?://#', $url);

        $info = getimagesize(Storage::disk('public')->path($files[0]));
        $this->assertSame([800, 600], [$info[0], $info[1]]);
        $this->assertSame('image/jpeg', $info['mime']);

        // Second call reuses the derivative instead of encoding it again.
        $mtime = filemtime(Storage::disk('public')->path($files[0]));
        touch(Storage::disk('public')->path($files[0]), $mtime - 100);
        clearstatcache();
        $this->assertSame($url, app(FeedImage::class)->jpegUrl($media));
        $this->assertSame($mtime - 100, filemtime(Storage::disk('public')->path($files[0])));
    }

    public function test_large_images_are_capped_and_small_ones_are_never_enlarged(): void
    {
        $large = $this->webpMedia('grande.webp', 3000, 1500);
        $small = $this->webpMedia('chica.webp', 400, 300);
        $feedImage = app(FeedImage::class);

        $feedImage->jpegUrl($large);
        $feedImage->jpegUrl($small);

        $largeInfo = getimagesize($this->derivative($large));
        $smallInfo = getimagesize($this->derivative($small));
        $this->assertSame([2048, 1024], [$largeInfo[0], $largeInfo[1]]);
        $this->assertSame([400, 300], [$smallInfo[0], $smallInfo[1]]);
    }

    public function test_transparency_is_flattened_onto_white(): void
    {
        $image = imagecreatetruecolor(600, 600);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 599, 599, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 300, 0, 599, 599, imagecolorallocatealpha($image, 200, 0, 0, 0));
        $media = $this->webpMedia('transparente.webp', 600, 600, $image);

        app(FeedImage::class)->jpegUrl($media);

        $jpeg = imagecreatefromjpeg($this->derivative($media));
        $transparent = imagecolorsforindex($jpeg, imagecolorat($jpeg, 100, 300));
        $opaque = imagecolorsforindex($jpeg, imagecolorat($jpeg, 500, 300));
        $this->assertGreaterThan(245, min($transparent['red'], $transparent['green'], $transparent['blue']));
        $this->assertGreaterThan(180, $opaque['red']);
        $this->assertLessThan(40, $opaque['green']);
    }

    public function test_fingerprint_changes_with_the_file_and_the_old_derivative_is_deleted(): void
    {
        $media = $this->webpMedia('cambia.webp', 700, 700);
        $other = $this->webpMedia('otra.webp', 700, 700);
        $feedImage = app(FeedImage::class);
        $firstUrl = $feedImage->jpegUrl($media);
        $first = $this->derivative($media);
        $feedImage->jpegUrl($other);
        $otherDerivative = $this->derivative($other);

        // Replace the file in place (same path) with a different image, as the media pipeline does.
        $replacement = imagecreatetruecolor(900, 500);
        imagefilledrectangle($replacement, 0, 0, 899, 499, imagecolorallocate($replacement, 10, 120, 30));
        imagewebp($replacement, Storage::disk('public')->path($media->path), 80);
        $media->forceFill(['size' => filesize(Storage::disk('public')->path($media->path)), 'width' => 900, 'height' => 500])->save();
        $this->travel(1)->minutes();
        $media->touch();

        $secondUrl = $feedImage->jpegUrl($media->fresh());

        $this->assertNotNull($secondUrl);
        $this->assertNotSame($firstUrl, $secondUrl);
        $this->assertFileDoesNotExist($first);
        $second = $this->derivative($media);
        $this->assertSame([900, 500], array_slice(getimagesize($second), 0, 2));
        // Only the medium's own derivatives are removed.
        $this->assertFileExists($otherDerivative);

        $feedImage->forget($media);
        $this->assertFileDoesNotExist($second);
        $this->assertFileExists($otherDerivative);
    }

    public function test_deleting_a_medium_deletes_its_derivatives_only(): void
    {
        $media = $this->webpMedia('borrar.webp', 700, 700);
        $other = $this->webpMedia('queda.webp', 700, 700);
        $feedImage = app(FeedImage::class);
        $feedImage->jpegUrl($media);
        $feedImage->jpegUrl($other);
        $derivative = $this->derivative($media);
        $otherDerivative = $this->derivative($other);

        app(MediaOperations::class)->delete(User::factory()->create(['role' => 'admin']), $media);

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertFileDoesNotExist($derivative);
        $this->assertFileDoesNotExist(Storage::disk('public')->path($media->path));
        $this->assertFileExists($otherDerivative);
    }

    public function test_images_under_500_pixels_get_the_notice(): void
    {
        $feedImage = app(FeedImage::class);

        $this->assertTrue($feedImage->isTooSmall($this->webpMedia('angosta.webp', 1200, 499)));
        $this->assertFalse($feedImage->isTooSmall($this->webpMedia('justa.webp', 500, 500)));

        // Without stored dimensions they are read from the file.
        $unknown = $this->webpMedia('sin-medidas.webp', 300, 800);
        $unknown->forceFill(['width' => null, 'height' => null])->save();
        $this->assertTrue($feedImage->isTooSmall($unknown->fresh()));
    }

    public function test_jpeg_and_png_originals_keep_their_own_url(): void
    {
        $jpegPath = 'media/2026/11/original.jpg';
        $image = imagecreatetruecolor(600, 600);
        imagejpeg($image, $this->publicPath($jpegPath));
        $pngPath = 'media/2026/11/original.png';
        imagepng($image, $this->publicPath($pngPath));

        $jpeg = $this->mediaRow($jpegPath, 'image/jpeg', 'jpg', 600, 600);
        $png = $this->mediaRow($pngPath, 'image/png', 'png', 600, 600);

        $this->assertSame(SiteUrl::origin().Storage::disk('public')->url($jpegPath), app(FeedImage::class)->jpegUrl($jpeg));
        $this->assertSame(SiteUrl::origin().Storage::disk('public')->url($pngPath), app(FeedImage::class)->jpegUrl($png));
        $this->assertSame([], Storage::disk('public')->files('feed'));
    }

    public function test_relative_disk_urls_are_made_absolute_with_the_site_origin(): void
    {
        config(['filesystems.disks.relative' => ['driver' => 'local', 'root' => Storage::disk('public')->path(''), 'url' => '/storage']]);
        $media = $this->mediaRow('media/2026/11/rel.jpg', 'image/jpeg', 'jpg', 600, 600, 'relative');

        $this->assertSame(SiteUrl::origin().'/storage/media/2026/11/rel.jpg', app(FeedImage::class)->jpegUrl($media));
    }

    public function test_unconvertible_media_return_null(): void
    {
        $feedImage = app(FeedImage::class);

        // Not an image, an unsupported format, a missing file and a WebP on a remote disk.
        $this->assertNull($feedImage->jpegUrl($this->mediaRow('media/manual.pdf', 'application/pdf', 'pdf', null, null)));
        $this->assertNull($feedImage->jpegUrl($this->mediaRow('media/logo.svg', 'image/svg+xml', 'svg', null, null)));
        $this->assertNull($feedImage->jpegUrl($this->mediaRow('media/no-existe.webp', 'image/webp', 'webp', 600, 600)));
        config(['filesystems.disks.remote' => ['driver' => 's3', 'bucket' => 'x', 'region' => 'us-east-1']]);
        $this->assertNull($feedImage->jpegUrl($this->mediaRow('media/remota.webp', 'image/webp', 'webp', 600, 600, 'remote')));

        $this->assertSame([], Storage::disk('public')->files('feed'));
    }

    private function webpMedia(string $filename, int $width, int $height, $image = null): Media
    {
        if ($image === null) {
            $image = imagecreatetruecolor($width, $height);
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 40, 90, 200));
        }
        $path = 'media/2026/11/'.$filename;
        imagewebp($image, $this->publicPath($path), 80);

        return $this->mediaRow($path, 'image/webp', 'webp', $width, $height);
    }

    private function mediaRow(string $path, string $mime, string $extension, ?int $width, ?int $height, string $disk = 'public'): Media
    {
        return Media::create([
            'filename' => basename($path),
            'original_filename' => basename($path),
            'path' => $path,
            'mime_type' => $mime,
            'extension' => $extension,
            'size' => is_file(Storage::disk('public')->path($path)) ? filesize(Storage::disk('public')->path($path)) : 0,
            'width' => $width,
            'height' => $height,
            'disk' => $disk,
        ]);
    }

    private function publicPath(string $path): string
    {
        $full = Storage::disk('public')->path($path);
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }

        return $full;
    }

    /** Path of the medium's only derivative. */
    private function derivative(Media $media): string
    {
        $files = array_values(array_filter(
            Storage::disk('public')->files('feed'),
            fn (string $file) => str_starts_with($file, 'feed/'.$media->id.'-'),
        ));
        $this->assertCount(1, $files);

        return Storage::disk('public')->path($files[0]);
    }
}
