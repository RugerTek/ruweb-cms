{{-- Redirects tab: the 301/302 rules. The 404 monitor that feeds them is its own tab.
     Contract in docs/operations/redirects.md. --}}
@php $inputClasses = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50'; @endphp

{{-- New rule --}}
<section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
    <div class="px-4 py-3 border-b border-white/[0.06]">
        <h2 class="text-sm font-semibold text-white">{{ __('admin.redirect_new') }}</h2>
    </div>
    <form method="POST" action="{{ route('admin.redirects.store') }}" class="p-5 space-y-4">
        @csrf
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="source_path" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.redirect_source_path') }}</label>
                <input type="text" name="source_path" id="source_path" required
                       value="{{ old('source_path', $prefillSource) }}"
                       placeholder="{{ __('admin.redirect_source_path_ph') }}"
                       class="{{ $inputClasses }}">
                <p class="text-xs text-gray-600 mt-1">{{ __('admin.redirect_source_path_help') }}</p>
                @error('source_path')
                    <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="target_url" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.redirect_target_url') }}</label>
                <input type="text" name="target_url" id="target_url" required
                       value="{{ old('target_url') }}"
                       placeholder="{{ __('admin.redirect_target_url_ph') }}"
                       class="{{ $inputClasses }}">
                <p class="text-xs text-gray-600 mt-1">{{ __('admin.redirect_target_url_help') }}</p>
                @error('target_url')
                    <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>
        <div class="flex flex-wrap items-end gap-5">
            <div class="w-48">
                <label for="status_code" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.redirect_status_code') }}</label>
                <select name="status_code" id="status_code" class="{{ $inputClasses }}">
                    <option value="301" @selected((int) old('status_code', 301) === 301)>{{ __('admin.redirect_status_301') }}</option>
                    <option value="302" @selected((int) old('status_code', 301) === 302)>{{ __('admin.redirect_status_302') }}</option>
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-400 pb-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))
                       class="rounded border-white/20 bg-[#1a1a1a] text-sky-500 focus:ring-sky-500/50">
                {{ __('admin.redirect_is_active') }}
            </label>
            <p class="flex-1 min-w-64 text-xs text-gray-600 pb-2">{{ __('admin.redirect_status_code_help') }}</p>
            <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                {{ __('admin.btn_save') }}
            </button>
        </div>
    </form>
</section>

{{-- CSV import: a one-off at migration time, so it stays folded away. --}}
<details class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden group" @if($errors->has('redirects_csv')) open @endif>
    <summary class="px-4 py-3 flex items-center gap-2 cursor-pointer text-sm font-semibold text-white hover:bg-white/[0.02] transition-colors">
        <svg class="w-3.5 h-3.5 shrink-0 text-gray-500 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        {{ __('admin.redirect_import') }}
    </summary>
    <div class="px-5 py-5 border-t border-white/[0.06] space-y-4">
        <p class="text-xs text-gray-600">{{ __('admin.redirect_import_help') }}</p>
        <form method="POST" action="{{ route('admin.redirects.import') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="redirects_csv" accept=".csv,text/csv" required
                   class="block w-full text-sm text-gray-400 file:mr-3 file:rounded-md file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-200 hover:file:bg-white/15">
            @error('redirects_csv')
                <p class="text-red-400 text-xs">{{ $message }}</p>
            @enderror
            <div class="flex items-center gap-4">
                <button type="submit" class="px-4 py-2 bg-gray-800 border border-white/10 text-gray-300 text-sm font-medium rounded-md hover:bg-gray-700 transition-colors">
                    {{ __('admin.redirect_import_submit') }}
                </button>
                <a href="{{ route('admin.redirects.sample-csv') }}" class="text-xs font-medium text-sky-400 hover:text-sky-300">
                    {{ __('admin.redirect_import_sample') }}
                </a>
            </div>
        </form>
    </div>
</details>

{{-- Rules --}}
<section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
    <div class="px-4 py-3 border-b border-white/[0.06]">
        <h2 class="text-sm font-semibold text-white">{{ __('admin.redirect_list') }}</h2>
    </div>
    @if($redirects->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-gray-600">{{ __('admin.redirect_list_empty') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/[0.06]">
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.redirect_source_path') }}</th>
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.redirect_target_url') }}</th>
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.redirect_status_code') }}</th>
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.redirect_is_active') }}</th>
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.redirect_hits') }}</th>
                        <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                    @foreach($redirects as $redirect)
                        @php $formId = 'redirect-update-' . $redirect->id; @endphp
                        <tr class="hover:bg-white/[0.02] transition-colors align-top">
                            <td class="px-4 py-3">
                                <form id="{{ $formId }}" method="POST" action="{{ route('admin.redirects.update', $redirect) }}">@csrf @method('PUT')</form>
                                <input form="{{ $formId }}" name="source_path" value="{{ $redirect->source_path }}"
                                       aria-label="{{ __('admin.redirect_source_path') }}" class="{{ $inputClasses }} min-w-56">
                            </td>
                            <td class="px-4 py-3">
                                <input form="{{ $formId }}" name="target_url" value="{{ $redirect->target_url }}"
                                       aria-label="{{ __('admin.redirect_target_url') }}" class="{{ $inputClasses }} min-w-56">
                            </td>
                            <td class="px-4 py-3">
                                <select form="{{ $formId }}" name="status_code" aria-label="{{ __('admin.redirect_status_code') }}" class="{{ $inputClasses }} min-w-24">
                                    <option value="301" @selected($redirect->status_code === 301)>301</option>
                                    <option value="302" @selected($redirect->status_code === 302)>302</option>
                                </select>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <label class="flex items-center gap-2 text-sm text-gray-400">
                                    <input form="{{ $formId }}" type="hidden" name="is_active" value="0">
                                    <input form="{{ $formId }}" type="checkbox" name="is_active" value="1" @checked($redirect->is_active)
                                           class="rounded border-white/20 bg-[#1a1a1a] text-sky-500 focus:ring-sky-500/50">
                                    {{ $redirect->is_active ? __('admin.redirect_is_active') : __('admin.redirect_inactive') }}
                                </label>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">
                                {{ number_format($redirect->hit_count) }}
                                <span class="block text-xs text-gray-600">
                                    {{ $redirect->last_used_at?->format('d/m/Y H:i') ?? __('admin.redirect_never_used') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-3">
                                    <button form="{{ $formId }}" type="submit" class="text-sm text-sky-400 hover:text-sky-300">{{ __('admin.btn_save') }}</button>
                                    <form method="POST" action="{{ route('admin.redirects.destroy', $redirect) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-sm text-red-400 hover:text-red-300"
                                                data-confirm="{{ __('admin.redirect_confirm_delete', ['path' => $redirect->source_path]) }}"
                                                data-confirm-title="{{ __('admin.btn_delete') }}"
                                                data-confirm-label="{{ __('admin.btn_delete') }}"
                                                data-confirm-tone="danger">
                                            {{ __('admin.btn_delete') }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 border-t border-white/[0.06]">{{ $redirects->links() }}</div>
    @endif
</section>
