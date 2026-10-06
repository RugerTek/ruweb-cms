@php
    $model = $model ?? null;
    $locales = $locales ?? collect();
    $editRoute = $editRoute ?? null;
    $translateRoute = $translateRoute ?? null;
@endphp

@if($model && $editRoute && $translateRoute && $locales->count() > 1)
    <div class="relative">
        <label for="editor-language-switcher" class="sr-only">{{ __('admin.language') }}</label>
        <select id="editor-language-switcher"
                class="h-8 w-[7.5rem] sm:w-36 rounded-md border border-white/[0.08] bg-[#171717] pl-2.5 pr-8 text-xs font-medium text-gray-300 outline-none transition-colors hover:border-white/15 focus:border-sky-500/50 focus:ring-1 focus:ring-sky-500/30">
            @foreach($locales as $locale)
                @php
                    $translation = $model->translations->firstWhere('locale', $locale->code);
                    $state = $translation ? $model->derivedTranslationState($locale->code) : 'missing';
                @endphp
                <option value="{{ $locale->code }}"
                        data-url="{{ $translation ? route($editRoute, array_merge([$translation], filled($editorReturn ?? null) ? ['return' => $editorReturn] : [])) : '' }}"
                        data-create-url="{{ $translation ? '' : route($translateRoute, [$model, $locale->code]) }}"
                        {{ $model->locale === $locale->code ? 'selected' : '' }}>
                    {{ strtoupper($locale->code) }} · {{ __('admin.editorial_translation_state_'.$state) }}
                </option>
            @endforeach
        </select>
    </div>

    @once
        @push('scripts')
        <script>
            document.addEventListener('change', (event) => {
                const select = event.target.closest('#editor-language-switcher');
                if (!select) return;

                const option = select.selectedOptions[0];
                const url = option?.dataset.url;
                const createUrl = option?.dataset.createUrl;

                if (url) {
                    window.location.href = url;
                    return;
                }

                if (!createUrl) return;

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = createUrl;
                form.className = 'hidden';

                const token = document.createElement('input');
                token.type = 'hidden';
                token.name = '_token';
                token.value = document.querySelector('meta[name="csrf-token"]')?.content || '';

                form.appendChild(token);
                const returnInput = document.querySelector('#editor-form [name="return"]');
                if (returnInput) form.appendChild(returnInput.cloneNode(true));
                document.body.appendChild(form);
                form.submit();
            });
        </script>
        @endpush
    @endonce
@endif
