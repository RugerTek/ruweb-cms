{{--
    Amiba chatbot connector (App\Services\AmibaChat\AmibaChat, docs/operations/connectors.md#amiba-chatbot-card),
    included in the <head> of layouts.public. Loads Amiba's floating chat with the snippet Amiba hands out while the
    connector is on with a widget code; never on a panel screen, inside the panel's previews, or on the checkout when
    the administrator hid it there. Shown to signed-in panel users too (a chat measures nothing). Receives $isEditorPreview.
--}}
@php($amibaChatHandle = app(\App\Services\AmibaChat\AmibaChat::class)->handleFor(request(), (bool) ($isEditorPreview ?? false)))
@if($amibaChatHandle !== null)
    <script src="{{ \App\Services\AmibaChat\AmibaChat::loaderUrl() }}" data-amiba-widget="{{ $amibaChatHandle }}" async></script>
@endif
