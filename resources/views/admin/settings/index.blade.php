@extends('admin.layouts.app')

@section('title', __('admin.settings'))

@section('content')
<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.settings') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.settings_subtitle') }}</p>
</div>

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf
    @method('PUT')

    {{-- Group tabs --}}
    @php $groupNames = $groups->keys(); @endphp
    <div class="flex flex-wrap gap-1 mb-6 border-b border-white/[0.06]">
        @foreach ($groupNames as $group)
            @php
                $groupKey = 'admin.settings_groups.' . $group;
                $groupLabel = __($groupKey) !== $groupKey ? __($groupKey) : str_replace('_', ' ', $group);
            @endphp
            <button type="button"
                    class="settings-tab px-3 py-2 text-sm font-medium border-b-2 transition-colors capitalize"
                    data-group="{{ $group }}"
                    onclick="switchTab('{{ $group }}')">
                {{ $groupLabel }}
            </button>
        @endforeach
    </div>

    {{-- Group panels --}}
    @foreach ($groups as $group => $settings)
        <div class="settings-panel space-y-4" data-group="{{ $group }}" style="display:none">
            @foreach ($settings as $setting)
                @include('admin.settings._field', ['setting' => $setting])
            @endforeach
        </div>
    @endforeach

    <div class="mt-6">
        <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ __('admin.btn_save_changes') }}
        </button>
    </div>
</form>

@push('scripts')
<script>
    const tabs = document.querySelectorAll('.settings-tab');
    const panels = document.querySelectorAll('.settings-panel');

    const STORAGE_KEY = 'admin.settings.activeTab';

    function switchTab(group, persist = true) {
        tabs.forEach(tab => {
            if (tab.dataset.group === group) {
                tab.classList.add('border-sky-500', 'text-sky-400');
                tab.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-300');
            } else {
                tab.classList.remove('border-sky-500', 'text-sky-400');
                tab.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-300');
            }
        });
        panels.forEach(panel => {
            panel.style.display = panel.dataset.group === group ? '' : 'none';
        });
        if (persist) {
            try { sessionStorage.setItem(STORAGE_KEY, group); } catch (e) {}
        }
    }

    if (tabs.length) {
        const queryTab = new URLSearchParams(window.location.search).get('tab');
        let initial = queryTab;
        try { initial = sessionStorage.getItem(STORAGE_KEY); } catch (e) {}
        initial = queryTab || initial;
        const valid = initial && Array.from(tabs).some(t => t.dataset.group === initial);
        switchTab(valid ? initial : tabs[0].dataset.group, false);
    }

    function togglePasswordVisibility(btn) {
        const input = btn.parentElement.querySelector('input');
        if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = @json(__('admin.hide'));
        } else {
            input.type = 'password';
            btn.textContent = @json(__('admin.show'));
        }
    }
</script>
@endpush
@endsection
