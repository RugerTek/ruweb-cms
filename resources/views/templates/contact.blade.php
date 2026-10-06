@extends('layouts.public')

{{--
    Starter contact page: reference implementation of a code-owned public form.
    Fields and behaviour live here and in ContactFormController; the Live Editor
    only edits labels, subject options, success message and notifications.
    See docs/content/forms.md before copying this for a new form.
--}}

@section('content')
@php
    $meta = $page->meta();
    $intro = $page->sections()['intro'] ?? [];
    $form = $page->sections()['form'] ?? [];
    $labels = $form['labels'] ?? [];
    $label = fn (string $key) => $labels[$key] ?? __('public.forms.contact.'.$key);
    $options = array_values(array_filter(array_map(fn ($o) => trim((string) ($o['label'] ?? '')), $form['subject_options'] ?? [])));
    $sent = session(\App\Http\Controllers\Public\ContactFormController::SUCCESS_FLASH);
    $inputClass = 'w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-gray-900 focus:outline-none focus:ring-1 focus:ring-gray-900';
@endphp

<article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-6">{!! \App\Support\EditorialTitle::render($meta['title'] ?? $page->title) !!}</h1>

    @if(($intro['is_visible'] ?? 1))
        @if($intro['heading'] ?? null)
            <h2 class="text-2xl font-semibold text-gray-900 mb-3">{!! \App\Support\EditorialTitle::render($intro['heading']) !!}</h2>
        @endif
        @if($intro['body'] ?? null)
            <div class="text-lg text-gray-600 mb-10">{!! \App\Support\SectionRichText::render($intro['body'], 'short-description') !!}</div>
        @endif
    @endif

    @if(($form['is_visible'] ?? 1))
        <section id="contact-form" class="bg-gray-50 border border-gray-200 rounded-xl p-6 sm:p-8">
            @if($form['heading'] ?? null)
                <h2 class="text-xl font-semibold text-gray-900 mb-6">{!! \App\Support\EditorialTitle::render($form['heading']) !!}</h2>
            @endif

            @if($sent)
                <div role="status" class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                    {!! \App\Support\SectionRichText::render($form['success_message'] ?? __('public.forms.contact.success'), 'short-description') !!}
                </div>
            @endif

            @if($errors->any())
                <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-red-800 text-sm">
                    <p class="font-medium mb-1">{{ __('public.forms.errors.intro') }}</p>
                    <ul class="list-disc pl-5 space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ \App\Support\LocalizedRoute::to('forms.contact.store') }}" class="space-y-5" novalidate>
                @csrf
                <input type="hidden" name="page_id" value="{{ $page->id }}">

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="contact-name" class="block text-sm font-medium text-gray-700 mb-1">{{ $label('name') }} <span aria-hidden="true">*</span></label>
                        <input id="contact-name" name="name" type="text" required autocomplete="name" value="{{ old('name') }}" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label for="contact-email" class="block text-sm font-medium text-gray-700 mb-1">{{ $label('email') }} <span aria-hidden="true">*</span></label>
                        <input id="contact-email" name="email" type="email" required autocomplete="email" value="{{ old('email') }}" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="contact-phone" class="block text-sm font-medium text-gray-700 mb-1">{{ $label('phone') }}</label>
                        <input id="contact-phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}" class="{{ $inputClass }}">
                    </div>
                    @if($options !== [])
                        <div>
                            <label for="contact-subject" class="block text-sm font-medium text-gray-700 mb-1">{{ $label('subject') }}</label>
                            <select id="contact-subject" name="subject" class="{{ $inputClass }}">
                                <option value="">{{ __('public.forms.contact.subject_placeholder') }}</option>
                                @foreach($options as $option)
                                    <option value="{{ $option }}" @selected(old('subject') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                <div>
                    <label for="contact-message" class="block text-sm font-medium text-gray-700 mb-1">{{ $label('message') }} <span aria-hidden="true">*</span></label>
                    <textarea id="contact-message" name="message" rows="6" required class="{{ $inputClass }}">{{ old('message') }}</textarea>
                </div>

                <x-forms.honeypot />
                <x-forms.recaptcha action="contact" />

                <div class="flex items-center justify-between gap-4">
                    <p class="text-xs text-gray-500">{{ __('public.forms.required_note') }}</p>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-gray-700 transition-colors">
                        {{ $form['button_label'] ?? __('public.forms.contact.send') }}
                    </button>
                </div>
            </form>
        </section>
    @endif
</article>
@endsection
