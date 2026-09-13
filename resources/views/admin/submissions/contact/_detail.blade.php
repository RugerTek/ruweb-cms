<dl class="divide-y divide-white/[0.06] text-sm">
    @foreach(['name' => $submission->name, 'email' => $submission->email, 'phone' => $submission->phone, 'subject' => $submission->subject] as $field => $value)
        <div class="px-4 py-3 grid grid-cols-3 gap-4">
            <dt class="text-gray-500">{{ __('admin.submission_field_'.$field) }}</dt>
            <dd class="col-span-2 text-gray-200 break-all">
                @if($field === 'email' && $value)
                    <a href="mailto:{{ $value }}" class="text-sky-400 hover:text-sky-300">{{ $value }}</a>
                @else
                    {{ $value ?: '—' }}
                @endif
            </dd>
        </div>
    @endforeach
    <div class="px-4 py-3">
        <dt class="text-gray-500 mb-2">{{ __('admin.submission_field_message') }}</dt>
        <dd class="text-gray-200 whitespace-pre-wrap leading-relaxed">{{ $submission->message }}</dd>
    </div>
</dl>
