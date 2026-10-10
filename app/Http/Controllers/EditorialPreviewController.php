<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Taxonomy;
use App\Services\Editorial\EditorialHistory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class EditorialPreviewController extends Controller
{
    public function __invoke(string $token, EditorialHistory $history)
    {
        $link = DB::table('editorial_preview_links')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($link, 404);
        $model = $history->model($link->content_type, $link->content_id);
        if ($link->version_id) {
            $version = $history->query($model)->where('id', $link->version_id)->first();
            abort_unless($version, 404);
            $payload = json_decode($version->payload, true);
            $model->fill($payload['attributes']);
            $media = collect();
            foreach ($payload['media'] as $entry) {
                $item = Media::find($entry['id']);
                if ($item) {
                    $item->setRelation('pivot', new Pivot(['collection' => $entry['collection'], 'order' => $entry['order']]));
                    $media->push($item);
                }
            }
            $model->setRelation('media', $media);
            if (method_exists($model, 'taxonomies')) {
                $model->setRelation('taxonomies', Taxonomy::whereIn('id', $payload['taxonomies'])->get());
            }
        }
        app()->setLocale($model->locale);

        return response()->view($model->previewView(), $model->previewData() + ['isEditorPreview' => true])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')->header('Cache-Control', 'no-store, private')
            ->header('Content-Security-Policy', "form-action 'none'; frame-ancestors 'none'")->header('Referrer-Policy', 'no-referrer')->header('X-Frame-Options', 'DENY');
    }
}
