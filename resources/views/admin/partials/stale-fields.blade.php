<script type="application/json" data-editor-stale-fields>{!! json_encode(array_values($staleFieldNames ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<div id="stale-fields-alert" role="alert" class="hidden mb-4 rounded-md border border-amber-400/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200">
    {{ __('admin.save_blocked_outdated') }}
</div>
