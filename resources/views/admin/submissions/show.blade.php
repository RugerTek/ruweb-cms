@extends('admin.layouts.app')

@section('title', __('admin.submission_detail'))

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('admin.submissions.index', ['form' => $form]) }}" class="text-xs text-gray-500 hover:text-gray-300">&larr; {{ __('admin.submissions') }} · {{ __('admin.forms.'.$form) }}</a>
            <h1 class="text-lg font-semibold text-white mt-1">{{ __('admin.submission_detail') }} #{{ $submission->id }}</h1>
            <p class="text-sm text-gray-500 mt-0.5">{{ $submission->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <form method="POST" action="{{ route('admin.submissions.destroy', ['form' => $form, 'submission' => $submission->id]) }}"
              data-confirm="{{ __('admin.confirm_delete_submission') }}" data-confirm-title="{{ __('admin.btn_delete') }}" data-confirm-label="{{ __('admin.btn_delete') }}" data-confirm-tone="danger">
            @csrf
            @method('DELETE')
            <button type="submit" class="px-3 py-1.5 bg-red-600/10 hover:bg-red-600/20 text-red-400 border border-red-500/20 text-sm font-medium rounded-md transition-colors">{{ __('admin.btn_delete') }}</button>
        </form>
    </div>

    @include("admin.submissions._workflow")

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="lg:col-span-2 bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-white/[0.06]"><h2 class="text-sm font-semibold text-white">{{ __('admin.submission_fields') }}</h2></div>
            @include("admin.submissions.{$form}._detail", ['submission' => $submission])
        </section>

        <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-white/[0.06]"><h2 class="text-sm font-semibold text-white">{{ __('admin.submission_metadata') }}</h2></div>
            <dl class="divide-y divide-white/[0.06] text-sm">
                @foreach([
                    'received_at' => $submission->created_at->format('d/m/Y H:i:s'),
                    'read_at' => $submission->read_at?->format('d/m/Y H:i:s'),
                    'notified_at' => $submission->notified_at?->format('d/m/Y H:i:s'),
                    'locale' => $submission->locale,
                    'page' => $submission->page?->title,
                    'ip' => $submission->ip,
                    'recaptcha_score' => $submission->recaptcha_score,
                    'referer' => $submission->referer,
                    'user_agent' => $submission->user_agent,
                ] as $key => $value)
                    <div class="px-4 py-2.5">
                        <dt class="text-xs text-gray-500">{{ __('admin.submission_meta_'.$key) }}</dt>
                        <dd class="text-gray-200 break-all mt-0.5">{{ $value ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>
</div>
@endsection
