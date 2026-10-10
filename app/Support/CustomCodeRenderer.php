<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** The one applicability resolver for evaluation and public insertion. No server evaluation. */
final class CustomCodeRenderer
{
    public function enabled(): bool
    {
        return (bool) config('cms.custom_code.enabled', true) && ! is_file(storage_path('app/private/custom-code.disabled'));
    }

    public function evaluate(array $target, ?int $versionId = null): array
    {
        $registry = app(CustomCodeDestinations::class);
        $query = DB::table('custom_code_versions as v')->join('custom_code_snippets as s', 's.id', '=', 'v.snippet_id');
        if ($versionId) $query->where('v.id', $versionId);
        else $query->where('s.state', 'active')->whereColumn('s.active_version_id', 'v.id');
        $rows = $query->select('v.*', 's.state')->get();
        if ($versionId) abort_if($rows->isEmpty(), 404);
        $snippets = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true); $diagnostics = [];
            foreach (['include', 'exclude'] as $kind) foreach ($payload['rules'][$kind] as $ref) if (! $registry->exists($ref)) $diagnostics[] = ['cause' => 'deleted_destination', 'rule' => $kind, 'reference' => $ref];
            $included = $payload['rules']['global'] || collect($payload['rules']['include'])->contains(fn ($ref) => $registry->matches($ref, $target));
            $excluded = collect($payload['rules']['exclude'])->contains(fn ($ref) => $registry->matches($ref, $target));
            // A dangling exclusion cannot accidentally widen global coverage. Fail closed for review.
            $danglingExclusion = collect($diagnostics)->contains(fn ($diagnostic) => $diagnostic['rule'] === 'exclude');
            if (preg_match('/googletagmanager|gtag\s*\(|google-site-verification|connect\.facebook|fbq\s*\(/i', $payload['code'])) $diagnostics[] = ['cause' => 'dedicated_integration_overlap', 'recovery' => 'Review the existing Google Tag, Meta Pixel and Search Console settings before activation.'];
            $snippets[] = ['id' => $row->snippet_id, 'version_id' => $row->id, 'fingerprint' => $row->fingerprint, 'payload' => $payload, 'applies' => $included && ! $excluded && ! $danglingExclusion, 'diagnostics' => $diagnostics];
        }
        usort($snippets, fn ($a, $b) => [$a['payload']['sort_order'], $a['id']] <=> [$b['payload']['sort_order'], $b['id']]);
        return ['target' => $target, 'snippets' => $snippets, 'enabled' => $this->enabled(), 'warning' => 'Trusted browser code is visible to visitors and can execute real external events. This is not a sandbox.'];
    }

    public function render(?array $target, string $position, bool $preview = false, ?array $evaluation = null): string
    {
        if (! $target || ! $this->enabled() || $preview) return '';
        $output = '';
        foreach (($evaluation ?? $this->evaluate($target))['snippets'] as $snippet) {
            $payload = $snippet['payload'];
            if (! $snippet['applies'] || $payload['position'] !== $position) continue;
            $marker = 'data-custom-code="'.$snippet['id'].'" data-custom-code-version="'.$snippet['version_id'].'"';
            $output .= match ($payload['type']) {
                'css' => '<style '.$marker.'>'.$payload['code'].'</style>',
                'javascript' => '<script '.$marker.'>'.$payload['code'].'</script>',
                default => '<!-- custom-code:'.$snippet['id'].':'.$snippet['version_id'].' -->'.$payload['code'].'<!-- /custom-code:'.$snippet['id'].' -->',
            };
        }
        return $output;
    }
}
