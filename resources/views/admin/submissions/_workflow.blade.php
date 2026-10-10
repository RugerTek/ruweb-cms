<section class="bg-[#111111] border border-white/10 rounded-lg p-4 space-y-4">
    @if($errors->any())
        <ul role="alert" class="text-red-400 text-sm">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    <h2 class="text-sm font-semibold text-white">{{ __('submissions.workflow') }}</h2>
    <form method="POST" action="{{ route('admin.submissions.update', ['form' => $form, 'submission' => $submission->id]) }}" class="flex flex-wrap gap-4 items-end">
        @csrf @method('PATCH')
        <label class="text-sm text-gray-300">{{ __('submissions.workflow') }}
            <select name="workflow_status" class="block bg-gray-900 border border-white/10 rounded-md p-2 mt-1">
                @foreach(['new', 'in_progress', 'resolved'] as $state)<option value="{{ $state }}" @selected(old('workflow_status', $submission->workflow_status) === $state)>{{ __('submissions.'.$state) }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm text-gray-300">{{ __('submissions.assignee') }}
            <select name="assigned_to" class="block bg-gray-900 border border-white/10 rounded-md p-2 mt-1">
                <option value="">{{ __('submissions.unassigned') }}</option>
                @foreach($assignees as $user)<option value="{{ $user->id }}" @selected((string) old('assigned_to', $submission->assigned_to) === (string) $user->id)>{{ $user->name }}</option>@endforeach
            </select>
        </label>
        <button class="bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-2 rounded-md text-sm">{{ __('submissions.save') }}</button>
    </form>
    <div class="border-t border-white/10 pt-4 text-sm text-gray-300 space-y-2">
        <p>{{ __('submissions.notification') }}: <strong>{{ __('submissions.'.$submission->notification_status) }}</strong> · {{ __('submissions.attempts') }}: {{ $submission->notification_attempts }}</p>
        <p class="text-xs text-gray-500">{{ __('submissions.queue_help') }}</p>
        @if(in_array($submission->notification_status, ['failed', 'retrying']))<p role="alert" class="text-amber-400">{{ __('submissions.delivery_failed') }}</p>@endif
        @if(in_array($submission->notification_status, ['failed', 'skipped']))
            <form method="POST" action="{{ route('admin.submissions.resend', ['form' => $form, 'submission' => $submission->id]) }}">@csrf<button class="px-3 py-2 rounded-md bg-sky-600 text-white">{{ __('submissions.resend') }}</button></form>
        @endif
    </div>
</section>
<section class="bg-[#111111] border border-white/10 rounded-lg p-4 space-y-4">
    <h2 class="text-sm font-semibold text-white">{{ __('submissions.notes') }}</h2>
    @forelse($submission->notes as $note)
        <div class="border-b border-white/10 pb-3"><p class="text-xs text-gray-500">{{ $note->author?->name ?? __('submissions.deleted_user') }} · {{ $note->created_at->format('d/m/Y H:i') }}</p><p class="text-sm text-gray-200 whitespace-pre-wrap mt-1">{{ $note->body }}</p></div>
    @empty<p class="text-sm text-gray-500">{{ __('submissions.no_notes') }}</p>@endforelse
    <form method="POST" action="{{ route('admin.submissions.notes', ['form' => $form, 'submission' => $submission->id]) }}" class="space-y-2">
        @csrf
        <label for="submission-note" class="block text-sm text-gray-300">{{ __('submissions.note_body') }}</label>
        <textarea id="submission-note" name="body" required maxlength="5000" rows="3" class="w-full bg-gray-900 text-white border border-white/10 rounded-md p-2">{{ old('body') }}</textarea>
        <button class="bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-2 rounded-md text-sm">{{ __('submissions.add_note') }}</button>
    </form>
</section>
