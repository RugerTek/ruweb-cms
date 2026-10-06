{{--
    Live Editor section for the code-owned contact form (docs/content/forms.md).
    Editable surface: labels, button text, success message, subject options
    and the notification settings. Fields, validation and behaviour are code.
--}}
@php
    $sectionData = $contentJson['sections'][$sectionKey] ?? [];
    $labels = $sectionData['labels'] ?? [];
    $options = array_values(array_filter($sectionData['subject_options'] ?? [], fn ($o) => filled($o['label'] ?? null)));
    $inputClass = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $mailEnabled = (bool) \App\Models\Setting::get('mail_enabled');
    $canViewSubmissions = auth()->user()?->isAdmin() || auth()->user()?->role === 'editor';
@endphp

<p class="text-xs text-gray-500 rounded-md border border-white/[0.06] bg-white/[0.02] px-3 py-2">
    {{ __('admin.form_section_code_owned_note') }}
</p>

@include('admin.partials._editorial-title', ['name' => "content_json[sections][{$sectionKey}][heading]", 'value' => $sectionData['heading'] ?? ''])

<div class="border-t border-white/[0.08] pt-4">
    <h4 class="text-xs font-semibold text-white uppercase tracking-wide mb-3">{{ __('admin.form_section_labels') }}</h4>
    <div class="space-y-3">
        @foreach(['name', 'email', 'phone', 'subject', 'message'] as $fieldKey)
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.form_field_label_for', ['field' => __('admin.submission_field_'.$fieldKey)]) }}</label>
                <input type="text" name="content_json[sections][{{ $sectionKey }}][labels][{{ $fieldKey }}]" value="{{ $labels[$fieldKey] ?? '' }}"
                       class="{{ $inputClass }}" placeholder="{{ __('admin.submission_field_'.$fieldKey) }}">
            </div>
        @endforeach
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_button_label') }}</label>
            <input type="text" name="content_json[sections][{{ $sectionKey }}][button_label]" value="{{ $sectionData['button_label'] ?? '' }}" class="{{ $inputClass }}" placeholder="{{ __('admin.contact_button_example') }}">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_success_message') }}</label>
            <textarea name="content_json[sections][{{ $sectionKey }}][success_message]" rows="2" data-rich-text="short-description" class="{{ $inputClass }}" placeholder="{{ __('admin.contact_success_example') }}">{{ $sectionData['success_message'] ?? '' }}</textarea>
        </div>
    </div>
</div>

{{-- Administrable options of the "subject" select: a generic repeater, one nested accordion per option --}}
<div class="border-t border-white/[0.08] pt-4"
     data-repeater
     data-repeater-key="subject_options"
     data-item-label="{{ __('admin.option_item_fallback_label') }}"
     data-confirm-remove="{{ __('admin.confirm_remove_option') }}"
     data-confirm-label="{{ __('admin.remove') }}">
    <div class="min-w-0 mb-3">
        <h4 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('admin.form_section_subject_options') }}</h4>
        <p class="text-xs text-gray-600 mt-0.5">{{ __('admin.form_section_subject_options_help') }}</p>
    </div>

    <input type="hidden" name="content_json[sections][{{ $sectionKey }}][subject_options]" value="" data-empty-repeater>
    <div data-repeater-list class="space-y-2">
        @foreach($options as $index => $option)
            @include('admin.pages.sections._option-repeater-item', ['sectionKey' => $sectionKey, 'field' => 'subject_options', 'index' => $index, 'option' => $option])
        @endforeach
    </div>

    <p data-repeater-empty class="text-xs text-gray-600 py-3 {{ count($options) ? 'hidden' : '' }}">{{ __('admin.form_section_subject_options_empty') }}</p>

    <button type="button" data-repeater-add class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        {{ __('admin.btn_add_option') }}
    </button>

    <template data-repeater-template>
        @include('admin.pages.sections._option-repeater-item', ['sectionKey' => $sectionKey, 'field' => 'subject_options', 'index' => '__INDEX__', 'option' => []])
    </template>
</div>

{{-- Notifications: non-translatable behaviour, configured once for every locale --}}
<div class="border-t border-white/[0.08] pt-4 space-y-3">
    <h4 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('admin.form_section_notifications') }}</h4>

    <label class="flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="content_json[sections][{{ $sectionKey }}][notify_enabled]" value="0">
        <input type="checkbox" name="content_json[sections][{{ $sectionKey }}][notify_enabled]" value="1" @checked($sectionData['notify_enabled'] ?? false) class="rounded border-white/[0.12] bg-[#0a0a0a]">
        <span class="text-sm text-gray-300">{{ __('admin.field_notify_enabled') }}</span>
    </label>

    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_notify_email') }}</label>
        <input type="email" name="content_json[sections][{{ $sectionKey }}][notify_email]" value="{{ $sectionData['notify_email'] ?? '' }}" class="{{ $inputClass }}" placeholder="{{ __('admin.notify_email_example') }}">
        <p class="text-xs text-gray-600 mt-1">{{ __('admin.field_notify_email_help') }}</p>
    </div>

    @unless($mailEnabled)
        <p class="text-xs text-amber-400/90 rounded-md border border-amber-500/20 bg-amber-500/5 px-3 py-2">
            {{ __('admin.form_section_mail_disabled') }}
            @if(auth()->user()?->isAdmin())
                <a href="{{ route('admin.email.index') }}" class="underline hover:text-amber-300">{{ __('admin.email_settings') }}</a>
            @endif
        </p>
    @endunless
</div>

{{-- Handoff to the records the form produces (live-editor-ui-ux.md: Forms Are Code-Owned) --}}
@if($canViewSubmissions)
    <div class="border-t border-white/[0.08] pt-4">
        <p class="text-xs text-gray-500 mb-2">{{ __('admin.form_section_submissions_note') }}</p>
        <a href="{{ route('admin.submissions.index', ['form' => 'contact']) }}"
           class="inline-flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ __('admin.form_section_view_submissions') }}
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
        </a>
    </div>
@endif
