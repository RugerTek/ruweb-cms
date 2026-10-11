<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotFoundHit;
use App\Models\Redirect as RedirectRule;
use App\Services\Content\RedirectOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Write actions for the redirect rules and the 404 monitor. The screen itself is the
 * `redirects` / `not-found` tab pair of Developer Tools, rendered by DeveloperToolsController.
 * Contract in docs/operations/redirects.md.
 */
class RedirectController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        app(RedirectOperations::class)->save($request->user(), $request->only(['source_path', 'target_url', 'status_code', 'is_active']));

        return $this->back(__('admin.redirect_saved'));
    }

    public function update(Request $request, RedirectRule $redirect): RedirectResponse
    {
        app(RedirectOperations::class)->save($request->user(), $request->only(['source_path', 'target_url', 'status_code', 'is_active']), $redirect);

        return $this->back(__('admin.redirect_updated'));
    }

    public function destroy(Request $request, RedirectRule $redirect): RedirectResponse
    {
        app(RedirectOperations::class)->delete($request->user(), $redirect);

        return $this->back(__('admin.redirect_deleted'));
    }

    public function destroyNotFound(Request $request, NotFoundHit $notFoundHit): RedirectResponse
    {
        app(RedirectOperations::class)->forgetNotFound($request->user(), $notFoundHit);

        return $this->back(__('admin.not_found_hit_deleted'), 'not-found');
    }

    public function clearNotFound(Request $request): RedirectResponse
    {
        app(RedirectOperations::class)->forgetNotFound($request->user());

        return $this->back(__('admin.not_found_cleared'), 'not-found');
    }

    /** Template for the bulk import, so the client never has to guess the column names. */
    public function sampleCsv(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['source_path', 'target_url', 'status_code', 'is_active']);
            fputcsv($out, ['/old-page', '/new-page', '301', '1']);
            fputcsv($out, ['/temporary-promo', 'https://example.com/promo', '302', '1']);
            fclose($out);
        }, 'redirects-sample.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Bulk import. A migration from a legacy site arrives as a spreadsheet, not as one form at a
     * time; rows that fail validation are skipped and reported instead of aborting the import.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'redirects_csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $path = $request->file('redirects_csv')->getRealPath();
        $delimiter = $this->detectCsvDelimiter($path);
        $handle = fopen($path, 'r');

        if (! $handle) {
            return back()->withErrors(['redirects_csv' => __('admin.redirect_import_unreadable')]);
        }

        $headers = fgetcsv($handle, separator: $delimiter);
        if (! is_array($headers)) {
            fclose($handle);

            return back()->withErrors(['redirects_csv' => __('admin.redirect_import_empty')]);
        }

        $headers = array_map(
            fn ($header): string => strtolower(trim((string) $header, "\xEF\xBB\xBF \t\n\r\0\x0B")),
            $headers
        );
        $missing = array_diff(['source_path', 'target_url'], $headers);
        if ($missing !== []) {
            fclose($handle);

            return back()->withErrors([
                'redirects_csv' => __('admin.redirect_import_missing_columns', ['columns' => implode(', ', $missing)]),
            ]);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle, separator: $delimiter)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $values = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
            $data = array_combine($headers, $values);

            $sourcePath = RedirectRule::normalizePath((string) ($data['source_path'] ?? ''));
            $targetUrl = trim((string) ($data['target_url'] ?? ''));
            $statusCode = (int) ($data['status_code'] ?? 301) ?: 301;
            $isActive = $this->toBool($data['is_active'] ?? '1');

            if (! in_array($statusCode, config('cms.redirects.status_codes'), true)) {
                $skipped++;
                $errors[] = __('admin.redirect_import_row_error', ['row' => $rowNumber]);
                continue;
            }

            $reason = app(RedirectOperations::class)->rejectionReason($sourcePath, $targetUrl);
            if ($reason !== null) {
                $skipped++;
                $errors[] = __('admin.redirect_import_row_reason', ['row' => $rowNumber, 'reason' => $reason]);
                continue;
            }

            $rule = RedirectRule::updateOrCreate(['source_path' => $sourcePath], [
                'target_url' => $targetUrl,
                'status_code' => $statusCode,
                'is_active' => $isActive,
            ]);

            $rule->wasRecentlyCreated ? $created++ : $updated++;
        }

        fclose($handle);

        $message = __('admin.redirect_import_summary', [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        if ($errors !== []) {
            $message .= ' '.implode(' ', array_slice($errors, 0, 3));
        }

        return $this->back($message);
    }

    private function toBool(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'si', 'sí', 'on', 'activo', 'activa'], true);
    }

    private function detectCsvDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return ',';
        }

        $firstLine = fgets($handle) ?: '';
        fclose($handle);

        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function back(string $message, string $tab = 'redirects'): RedirectResponse
    {
        return redirect()
            ->route('admin.developer-tools.index', ['tab' => $tab])
            ->with('success', $message);
    }
}
