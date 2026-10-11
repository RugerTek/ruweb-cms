<?php

namespace App\Http\Controllers;

use App\Models\NotFoundHit;
use App\Models\Redirect as RedirectRule;
use App\Services\Content\RedirectOperations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Redirect rules and the 404 monitor for agents; Developer Tools uses the same RedirectOperations. */
class McpRedirectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $this->paging($request, ['search' => ['nullable', 'string', 'max:255']]);
        $page = RedirectRule::query()
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where(fn ($inner) => $inner->where('source_path', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('target_url', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('source_path')->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => collect($page->items())->map(fn (RedirectRule $rule) => $this->serialize($rule))->values(), 'meta' => $this->meta($page)]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = app(RedirectOperations::class)->save($request->user(), $request->only(['source_path', 'target_url', 'status_code', 'is_active']));

        return response()->json(['data' => $this->serialize($rule)], 201);
    }

    public function update(Request $request, RedirectRule $redirect): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $rule = app(RedirectOperations::class)->save($request->user(), $request->only(['source_path', 'target_url', 'status_code', 'is_active']), $redirect, $request->input('expected_revision'), partial: true);

        return response()->json(['data' => $this->serialize($rule)]);
    }

    public function destroy(Request $request, RedirectRule $redirect): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $deleted = ['type' => 'redirects', 'id' => $redirect->id, 'source_path' => $redirect->source_path];
        app(RedirectOperations::class)->delete($request->user(), $redirect, $request->input('expected_revision'));

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    public function notFound(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $this->paging($request, []);
        $page = NotFoundHit::query()->orderByDesc('last_seen_at')->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => collect($page->items())->map(fn (NotFoundHit $hit) => ['id' => $hit->id, 'path' => $hit->path, 'method' => $hit->method,
            'hit_count' => $hit->hit_count, 'referer' => $hit->referer, 'first_seen_at' => $hit->first_seen_at?->toISOString(), 'last_seen_at' => $hit->last_seen_at?->toISOString()])->values(), 'meta' => $this->meta($page)]);
    }

    public function forgetNotFound(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), ['hit_id' => ['required_without:all', 'prohibits:all', 'integer', 'min:1'], 'all' => ['sometimes', 'accepted']])->validate();
        $hit = isset($data['hit_id']) ? NotFoundHit::findOrFail($data['hit_id']) : null;

        return response()->json(['data' => ['forgotten' => app(RedirectOperations::class)->forgetNotFound($request->user(), $hit)]]);
    }

    private function serialize(RedirectRule $rule): array
    {
        return ['id' => $rule->id, 'revision' => app(RedirectOperations::class)->revision($rule), 'source_path' => $rule->source_path, 'target_url' => $rule->target_url,
            'status_code' => $rule->status_code, 'is_active' => $rule->is_active, 'hit_count' => $rule->hit_count, 'last_used_at' => $rule->last_used_at?->toISOString()];
    }

    private function paging(Request $request, array $rules): array
    {
        return Validator::make($request->all(), $rules + ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']])->validate();
    }

    private function meta($page): array
    {
        return ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()];
    }

    private function admin(Request $request): void
    {
        if (! $request->user()?->isAdmin()) {
            throw new AuthorizationException('Only administrators manage redirects.');
        }
    }
}
