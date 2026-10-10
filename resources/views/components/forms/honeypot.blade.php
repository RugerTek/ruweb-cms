@props(['page', 'form'])
{{--
    Anti-spam honeypot shared by every public form (docs/content/forms.md).
    Bots fill the hidden "website" field and submit instantly; humans never see it.
    The request base class (PublicFormRequest) reads both fields.
--}}
@php
    $hpId = 'hp-'.uniqid();
    $tokens = app(\App\Services\Forms\FormToken::class);
    $oldAge = $tokens->age(old('_form_token'), $page->id, $form, $page->locale);
    $token = $oldAge !== null ? old('_form_token') : $tokens->issue($page->id, $form, $page->locale);
    $waitSeconds = max(0, \App\Http\Requests\Public\PublicFormRequest::MIN_SECONDS - ($oldAge ?? 0));
@endphp
<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
    <label for="{{ $hpId }}">Website</label>
    <input type="text" id="{{ $hpId }}" name="{{ \App\Http\Requests\Public\PublicFormRequest::HONEYPOT_FIELD }}" tabindex="-1" autocomplete="off" value="">
</div>
<input type="hidden" name="_form_token" value="{{ $token }}" data-form-fill-wait="{{ $waitSeconds }}">
<p hidden data-form-fill-status role="status" class="text-sm text-gray-600">{{ __('submissions.preparing') }}</p>
@once
    @push('scripts')
        <script>
            (function () {
                var started = Date.now();
                document.addEventListener('submit', function (event) {
                    var form = event.target;
                    var token = form.querySelector && form.querySelector('[data-form-fill-wait]');
                    if (!token) return;
                    var remaining = Number(token.dataset.formFillWait) * 1000 - (Date.now() - started);
                    if (remaining <= 0) return;
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    if (form.dataset.fillPending) return;
                    form.dataset.fillPending = '1';
                    var status = form.querySelector('[data-form-fill-status]');
                    if (status) status.hidden = false;
                    setTimeout(function () {
                        delete form.dataset.fillPending;
                        if (status) status.hidden = true;
                        if (form.requestSubmit) form.requestSubmit(); else form.submit();
                    }, remaining + 50);
                }, true);
            })();
        </script>
    @endpush
@endonce
