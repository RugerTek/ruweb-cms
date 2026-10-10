<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomCode\CustomCodeOperations;
use Illuminate\Http\Request;
use App\Support\CustomCodeDestinations;

class CustomCodeController extends Controller
{
    public function __construct(private CustomCodeOperations $operations) {}

    private function authorizeCustomCode(Request $request): void
    {
        abort_unless($request->user()?->canManageCustomCode(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeCustomCode($request);
        return view('admin.custom-code.index', ['result' => $this->operations->list($request->user(), $request->only(['page', 'per_page']))]);
    }

    public function create(Request $request)
    {
        $this->authorizeCustomCode($request);
        return view('admin.custom-code.edit', ['snippet' => null, 'destinations' => $this->destinations()]);
    }

    public function edit(Request $request, int $id)
    {
        $this->authorizeCustomCode($request);
        return view('admin.custom-code.edit', ['snippet' => $this->operations->get($request->user(), $id, $request->only(['versions_page'])), 'destinations' => $this->destinations()]);
    }

    private function destinations(): array
    {
        $rows = []; $page = 1;
        do {
            $result = app(CustomCodeDestinations::class)->list(['page' => $page++, 'per_page' => 100]);
            array_push($rows, ...$result['destinations']);
        } while ($page <= $result['last_page']);
        return $rows;
    }

    private function input(Request $request): array
    {
        $input = $request->all();
        $input['rules'] = [
            'global' => $request->boolean('global'),
            'include' => $this->refs($request, 'include'),
            'exclude' => $this->refs($request, 'exclude'),
        ];
        return $input;
    }

    private function refs(Request $request, string $key): array
    {
        $request->validate([$key.'_refs' => ['nullable', 'array'], $key.'_refs.*' => ['string']]);
        return array_map(function ($value) use ($request, $key) {
            $ref = json_decode($value, true);
            abort_unless(is_array($ref), 422);
            $ref['group'] = in_array($value, $request->input($key.'_groups', []), true);
            return $ref;
        }, $request->input($key.'_refs', []));
    }

    public function store(Request $request)
    {
        $this->authorizeCustomCode($request);
        $snippet = $this->operations->save($request->user(), $this->input($request));
        return redirect()->route('admin.custom-code.edit', $snippet['id'])->with('success', __('custom_code.saved'));
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeCustomCode($request);
        $this->operations->save($request->user(), $this->input($request), $id);
        return back()->with('success', __('custom_code.saved'));
    }

    public function activate(Request $request, int $id)
    {
        $this->authorizeCustomCode($request);
        $this->operations->activate($request->user(), $id, $request->all());
        return back()->with('success', __('custom_code.activated'));
    }

    public function deactivate(Request $request, int $id)
    {
        $this->authorizeCustomCode($request);
        $this->operations->deactivate($request->user(), $id, $request->all());
        return back()->with('success', __('custom_code.deactivated'));
    }

    public function evaluate(Request $request, int $id)
    {
        $this->authorizeCustomCode($request);
        $target = $request->validate(['target_ref' => ['required', 'string']]);
        $input = $request->all();
        $input['target'] = json_decode($target['target_ref'], true);
        abort_unless(is_array($input['target']), 422);
        return back()->with('evaluation', $this->operations->evaluate($request->user(), $input));
    }
}
