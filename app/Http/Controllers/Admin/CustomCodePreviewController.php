<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomCode\CustomCodeOperations;
use Illuminate\Http\Request;

/** Explicit code experiment: a separate opaque-origin document, never ordinary editorial preview. */
final class CustomCodePreviewController extends Controller
{
    public function __invoke(Request $request, int $id, CustomCodeOperations $operations)
    {
        $operations->authorize($request->user());
        $data = $request->validate(['version_id' => ['required', 'integer'], 'target_ref' => ['required', 'string'], 'expected_revision' => ['required', 'string', 'size:64'], 'acknowledgement' => ['required', 'in:execute_browser_code']]);
        $snippet = $operations->get($request->user(), $id, ['version_id' => $data['version_id']]);
        abort_unless(hash_equals($snippet['revision'], $data['expected_revision']), 409, 'Re-read the snippet before executing its exact version.');
        $version = collect($snippet['versions'])->firstWhere('id', $data['version_id']); abort_unless($version, 404);
        $target = json_decode($data['target_ref'], true); abort_unless(is_array($target), 422);
        $evaluation = $operations->evaluate($request->user(), ['target' => $target, 'version_id' => $version['id']]);
        $document = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">';
        $parts = ['head_end' => '', 'body_start' => '', 'body_end' => ''];
        if ($evaluation['enabled'] && $evaluation['snippets'][0]['applies']) {
            $payload = $version['payload'];
            $parts[$payload['position']] = match ($payload['type']) { 'css' => '<style>'.$payload['code'].'</style>', 'javascript' => '<script>'.$payload['code'].'</script>', default => $payload['code'] };
        }
        $document .= $parts['head_end'].'</head><body>'.$parts['body_start'].'<main><p>'.e(__('custom_code.preview_fixture')).'</p></main>'.$parts['body_end'].'</body></html>';
        return response()->view('admin.custom-code.preview', compact('document', 'evaluation'))
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
