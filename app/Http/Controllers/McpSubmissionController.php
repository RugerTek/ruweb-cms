<?php

namespace App\Http\Controllers;

use App\Services\Forms\SubmissionOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class McpSubmissionController extends Controller
{
    public function __construct(private SubmissionOperations $operations) {}

    public function index(Request $request, string $form): JsonResponse
    {
        return response()->json(['data' => $this->operations->list($request->user(), $form, $request->all())]);
    }

    public function show(Request $request, string $form, int $id): JsonResponse
    {
        return response()->json(['data' => $this->operations->get($request->user(), $form, $id, $request->all())]);
    }

    public function write(Request $request, string $form, int $id, string $action): JsonResponse
    {
        Validator::make($request->all(), [
            'expected_revision' => ['required', 'string', 'size:64'],
            'operation_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
        ])->validate();

        return response()->json(['data' => $this->operations->write($request->user(), $form, $id, $action, $request->all())]);
    }
}
