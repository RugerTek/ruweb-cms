{{--
    Google reCAPTCHA v3 for public forms (docs/forms.md). Renders nothing when
    the security settings have no keys, so local development works untouched.
    The token is requested on submit and validated by PublicFormRequest.
--}}
@props(['action'])
@php $recaptcha = app(\App\Services\Forms\Recaptcha::class); @endphp
@if($recaptcha->enabled())
    <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token data-recaptcha-action="{{ $action }}">
    @once
        @push('scripts')
            <script src="https://www.google.com/recaptcha/api.js?render={{ urlencode($recaptcha->siteKey()) }}" async defer></script>
            <script>
                (function () {
                    var siteKey = @json($recaptcha->siteKey());
                    document.addEventListener('submit', function (event) {
                        var form = event.target;
                        var field = form.querySelector && form.querySelector('[data-recaptcha-token]');
                        if (!field || field.value) {
                            return;
                        }
                        event.preventDefault();
                        var submit = function () {
                            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
                        };
                        if (!window.grecaptcha || !window.grecaptcha.execute) {
                            // Script blocked or not loaded yet: submit anyway, the server answers with a retry message.
                            field.value = 'unavailable';
                            submit();
                            return;
                        }
                        window.grecaptcha.ready(function () {
                            window.grecaptcha.execute(siteKey, { action: field.dataset.recaptchaAction })
                                .then(function (token) { field.value = token; submit(); })
                                .catch(function () { field.value = 'unavailable'; submit(); });
                        });
                    }, true);
                })();
            </script>
        @endpush
    @endonce
@endif
