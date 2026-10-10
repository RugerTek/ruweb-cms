@extends('admin.layouts.app')
@section('title', __('custom_code.title'))
@section('content')
@php
    $versions = $snippet['versions'] ?? [];
    $latest = $versions[0]['payload'] ?? [];
    $activePayload = $snippet['active_version']['payload'] ?? [];
    $rules = $latest['rules'] ?? ['global' => false, 'include' => [], 'exclude' => []];
    $fieldClass = 'w-full bg-[#1a1a1a] border border-white/10 rounded-md p-2 text-white';
@endphp
<h1 class="text-lg font-semibold text-white mb-3">{{ __('custom_code.title') }}</h1>
<p class="text-sm text-amber-300 mb-5">{{ __('custom_code.warning') }}</p>
@if($errors->any())<div class="text-red-400 mb-4" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form method="POST" action="{{ $snippet ? route('admin.custom-code.update', $snippet['id']) : route('admin.custom-code.store') }}" class="space-y-4 rounded-xl bg-[#141414] p-5">
    @csrf
    @if($snippet) @method('PUT') <input type="hidden" name="expected_revision" value="{{ $snippet['revision'] }}"> @endif
    <input type="hidden" name="operation_key" value="{{ old('operation_key', (string) Illuminate\Support\Str::uuid()) }}">
    <label class="block text-sm text-gray-300">{{ __('custom_code.name') }}<input name="name" required maxlength="150" value="{{ old('name', $snippet['name'] ?? '') }}" class="{{ $fieldClass }}"></label>
    <label class="block text-sm text-gray-300">{{ __('custom_code.description') }}<textarea name="description" class="{{ $fieldClass }}">{{ old('description', $snippet['description'] ?? '') }}</textarea></label>
    <div class="grid sm:grid-cols-3 gap-4">
        <label class="text-sm text-gray-300">{{ __('custom_code.type') }}<select name="type" class="{{ $fieldClass }}">@foreach(['html', 'css', 'javascript'] as $type)<option value="{{ $type }}" @selected(old('type', $latest['type'] ?? 'css') === $type)>{{ __('custom_code.type_'.$type) }}</option>@endforeach</select></label>
        <label class="text-sm text-gray-300">{{ __('custom_code.position') }}<select name="position" class="{{ $fieldClass }}">@foreach(['head_end', 'body_start', 'body_end'] as $position)<option value="{{ $position }}" @selected(old('position', $latest['position'] ?? 'head_end') === $position)>{{ __('custom_code.position_'.$position) }}</option>@endforeach</select></label>
        <label class="text-sm text-gray-300">{{ __('custom_code.order') }}<input name="sort_order" type="number" value="{{ old('sort_order', $latest['sort_order'] ?? 0) }}" class="{{ $fieldClass }}"></label>
    </div>
    <label class="block text-sm text-gray-300">{{ __('custom_code.code') }}<textarea name="code" rows="12" required spellcheck="false" class="{{ $fieldClass }} font-mono">{{ old('code', $latest['code'] ?? '') }}</textarea></label>
    <input type="hidden" name="global" value="0">
    <label class="block text-sm text-gray-300"><input type="checkbox" name="global" value="1" @checked(old('global', $rules['global']))> {{ __('custom_code.global') }}</label>
    <p class="text-sm text-gray-500">{{ __('custom_code.rules_help') }}</p>
    <div class="grid sm:grid-cols-2 gap-4">
    @foreach(['include', 'exclude'] as $kind)
        <fieldset class="rounded-md border border-white/10 p-3 space-y-2 max-h-80 overflow-auto">
            <legend class="text-gray-300">{{ __('custom_code.'.$kind) }}</legend>
            @foreach($destinations as $destination)
                @php
                    $ref = array_intersect_key($destination, array_flip(['type','id','locale']));
                    $value = json_encode($ref);
                    $selected = collect($rules[$kind])->first(fn($item) => $item['type'] === $ref['type'] && (string)$item['id'] === (string)$ref['id'] && $item['locale'] === $ref['locale']);
                    $selectedRefs = old($kind.'_refs');
                @endphp
                <div class="text-sm text-gray-400">
                    <label><input type="checkbox" name="{{ $kind }}_refs[]" value="{{ $value }}" @checked(is_array($selectedRefs) ? in_array($value, $selectedRefs, true) : $selected !== null)> {{ $destination['title'] }} · {{ $ref['locale'] }} · {{ $ref['type'] }} #{{ $ref['id'] }}</label>
                    @if($ref['type'] !== 'indexes')
                        <label class="block pl-5 text-xs"><input type="checkbox" name="{{ $kind }}_groups[]" value="{{ $value }}" @checked(in_array($value, old($kind.'_groups', ($selected['group'] ?? false) ? [$value] : []), true))> {{ __('custom_code.group') }}</label>
                    @endif
                </div>
            @endforeach
        </fieldset>
    @endforeach
    </div>
    <button class="px-4 py-2 rounded-md bg-sky-600 text-white">{{ __('custom_code.save') }}</button>
</form>
@if($snippet)
    <div class="mt-6 rounded-xl bg-[#141414] p-5 space-y-4">
        <h2 class="text-white font-semibold">{{ __('custom_code.versions') }}</h2>
        <p class="text-sm text-gray-400">{{ __('custom_code.state_'.$snippet['state']) }} · {{ __('custom_code.active_version') }} {{ $snippet['active_version_id'] ?? '—' }}</p>
        @foreach($versions as $version)
            <details class="border-b border-white/10 pb-3">
                <summary class="text-gray-300">{{ __('custom_code.version') }} #{{ $version['id'] }} · {{ $version['created_at'] }}</summary>
                <div class="grid sm:grid-cols-2 gap-3 mt-3">
                    <div><p class="text-sm text-gray-300">{{ __('custom_code.active_code') }}</p><pre class="text-xs text-gray-400 overflow-auto p-3">{{ $activePayload['code'] ?? '' }}</pre></div>
                    <div><p class="text-sm text-gray-300">{{ __('custom_code.proposed_code') }}</p><pre class="text-xs text-gray-400 overflow-auto p-3">{{ $version['payload']['code'] }}</pre></div>
                </div>
                <pre class="text-xs text-gray-400 overflow-auto p-3">{{ json_encode($version['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                <form method="POST" action="{{ route('admin.custom-code.activate', $snippet['id']) }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="expected_revision" value="{{ $snippet['revision'] }}">
                    <input type="hidden" name="operation_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="version_id" value="{{ $version['id'] }}">
                    <button class="px-3 py-2 rounded-md bg-amber-700 text-white">{{ __('custom_code.activate') }}</button>
                </form>
            </details>
        @endforeach
        <form method="POST" action="{{ route('admin.custom-code.deactivate', $snippet['id']) }}">
            @csrf
            <input type="hidden" name="expected_revision" value="{{ $snippet['revision'] }}">
            <input type="hidden" name="operation_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <button class="px-3 py-2 rounded-md border border-red-500 text-red-300">{{ __('custom_code.deactivate') }}</button>
        </form>
        <h2 class="text-white font-semibold">{{ __('custom_code.evaluate') }}</h2>
        <form method="POST" action="{{ route('admin.custom-code.evaluate', $snippet['id']) }}" class="space-y-3">
            @csrf
            <label class="block text-sm text-gray-300">{{ __('custom_code.destination') }}<select name="target_ref" class="{{ $fieldClass }}">@foreach($destinations as $destination)<option value="{{ json_encode(array_intersect_key($destination, array_flip(['type','id','locale']))) }}">{{ $destination['title'] }} · {{ $destination['locale'] }} · {{ $destination['type'] }} #{{ $destination['id'] }}</option>@endforeach</select></label>
            <label class="block text-sm text-gray-300">{{ __('custom_code.version') }}<select name="version_id" class="{{ $fieldClass }}">@foreach($versions as $version)<option value="{{ $version['id'] }}">#{{ $version['id'] }}</option>@endforeach</select></label>
            <button class="px-3 py-2 bg-sky-600 rounded-md text-white">{{ __('custom_code.evaluate') }}</button>
        </form>
        <form method="POST" action="{{ route('admin.custom-code.test-preview', $snippet['id']) }}" class="space-y-3 border-t border-white/10 pt-4">
            @csrf
            <input type="hidden" name="expected_revision" value="{{ $snippet['revision'] }}">
            <label class="block text-sm text-gray-300">{{ __('custom_code.destination') }}<select name="target_ref" class="{{ $fieldClass }}">@foreach($destinations as $destination)<option value="{{ json_encode(array_intersect_key($destination, array_flip(['type','id','locale']))) }}">{{ $destination['title'] }} · {{ $destination['locale'] }} · {{ $destination['type'] }} #{{ $destination['id'] }}</option>@endforeach</select></label>
            <label class="block text-sm text-gray-300">{{ __('custom_code.version') }}<select name="version_id" class="{{ $fieldClass }}">@foreach($versions as $version)<option value="{{ $version['id'] }}">#{{ $version['id'] }}</option>@endforeach</select></label>
            <label class="block text-sm text-amber-300"><input type="checkbox" name="acknowledgement" value="execute_browser_code" required> {{ __('custom_code.test_preview') }}</label>
            <button class="px-3 py-2 rounded-md bg-amber-700 text-white">{{ __('custom_code.test_preview') }}</button>
        </form>
        @if(session('evaluation'))<pre class="text-sm text-gray-300 whitespace-pre-wrap max-w-full overflow-x-auto">{{ json_encode(session('evaluation'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
    </div>
    @if($snippet['versions_last_page'] > 1)
    <nav aria-label="{{ __('custom_code.history') }}" class="flex gap-3 mt-4">
        @for($historyPage = 1; $historyPage <= $snippet['versions_last_page']; $historyPage++)
            <a class="text-sky-400" href="{{ route('admin.custom-code.edit', ['id' => $snippet['id'], 'versions_page' => $historyPage]) }}">{{ $historyPage }}</a>
        @endfor
    </nav>
    @endif
@endif
@endsection
