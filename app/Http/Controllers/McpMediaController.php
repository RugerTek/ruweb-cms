<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\Content\OperationReceipt;
use App\Services\Media\MediaOperations;
use App\Support\SiteUrl;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Binary uploads use multipart HTTP; RPC issues a credential-bound upload ticket. */
final class McpMediaController extends Controller
{
    public function __construct(private MediaOperations $operations) {}

    public function authorizeUpload(Request $request): JsonResponse
    {
        $this->operations->authorize($request->user());
        $expires = now()->addMinutes(10);
        $ticket = Crypt::encryptString(json_encode([
            'id' => (string) Str::uuid(), 'user' => $request->user()->id,
            'credential' => hash('sha256', (string) $request->bearerToken()),
            'expires' => $expires->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => [
            'upload_url' => SiteUrl::origin().'/mcp/media/uploads', 'method' => 'POST',
            'content_type' => 'multipart/form-data', 'file_field' => 'file',
            'ticket' => $ticket, 'expires_at' => $expires->toISOString(),
            'requires_bearer_token' => true,
            'image_max_size_kb' => config('cms.upload.image_max_size'),
            'document_max_size_kb' => config('cms.upload.document_max_size'),
            'allowed_extensions' => config('cms.upload.allowed_extensions'),
        ]]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->operations->authorize($request->user());
        $input = $request->only(['alt', 'title']);
        foreach (['file', 'files'] as $field) {
            if ($request->hasFile($field)) {
                $input[$field] = $request->file($field);
            }
        }
        if (! $request->has('ticket')) {
            return response()->json(['data' => $this->operations->upload($request->user(), $input)], 201);
        }
        $request->validate(['ticket' => ['required', 'string']]);
        try {
            $ticket = json_decode(Crypt::decryptString($request->input('ticket')), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'Invalid upload ticket.');
        }
        abort_unless(is_array($ticket) && isset($ticket['id'], $ticket['expires'], $ticket['user'], $ticket['credential']), 422, 'Invalid upload ticket.');
        abort_unless($ticket['expires'] > now()->timestamp, 422, 'Upload ticket expired.');
        abort_unless((int) $ticket['user'] === (int) $request->user()->id
            && hash_equals($ticket['credential'], hash('sha256', (string) $request->bearerToken())), 403, 'Upload ticket belongs to another credential.');
        $files = array_values(array_filter((array) ($input['files'] ?? [$input['file'] ?? null])));
        $signature = hash('sha256', json_encode([
            'metadata' => $request->only(['alt', 'title']),
            'files' => array_map(fn ($file) => [$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', $file->getRealPath())], $files),
        ], JSON_THROW_ON_ERROR));
        // One ticket authorizes one asset. Direct REST uploads also support batches.
        abort_unless(count($files) === 1, 422, 'An upload ticket requires exactly one file.');
        $media = app(OperationReceipt::class)->run(
            $request->user(), $ticket['id'], 'media.upload', ['signature' => $signature],
            fn () => $this->operations->upload($request->user(), $input)[0]
        );

        return response()->json(['data' => [$media->load('translations')]], 201);
    }

    public function usages(Request $request, Media $media): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $references = $this->operations->usages($request->user(), $media);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['per_page'] ?? config('cms.mcp.per_page', 20));

        return response()->json(['data' => array_slice($references, ($page - 1) * $size, $size), 'meta' => ['total' => count($references), 'current_page' => $page, 'last_page' => max(1, (int) ceil(count($references) / $size)), 'per_page' => $size]]);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $this->operations->delete($request->user(), $media);

        return response()->json(null, 204);
    }
}
