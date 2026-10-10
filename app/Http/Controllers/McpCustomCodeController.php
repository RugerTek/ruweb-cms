<?php

namespace App\Http\Controllers;

use App\Services\CustomCode\CustomCodeOperations;
use App\Support\CustomCodeDestinations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpCustomCodeController extends Controller
{
    public function __construct(private CustomCodeOperations $operations, private CustomCodeDestinations $destinations) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->list($request->user(), $request->all())]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->get($request->user(), $id, $request->all())]);
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        return response()->json(['data' => $this->operations->save($request->user(), $request->all(), $id)]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->activate($request->user(), $id, $request->all())]);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->deactivate($request->user(), $id, $request->all())]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->evaluate($request->user(), $request->all())]);
    }

    public function destinations(Request $request): JsonResponse
    {
        // The shared operation checks the same account permission as all snippet reads.
        $this->operations->authorize($request->user());

        return response()->json(['data' => $this->destinations->list($request->all())]);
    }
}
