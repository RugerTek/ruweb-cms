@extends('admin.layouts.app')

@section('title', __('admin.claude_mcp'))

@section('content')
<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.claude_mcp') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.claude_mcp_subtitle') }}</p>
</div>

<div class="max-w-3xl space-y-4">
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">

        {{-- Step 1 --}}
        <div class="flex gap-3 mb-5">
            <span class="flex-shrink-0 w-5 h-5 rounded-full bg-sky-500/10 text-sky-400 text-xs font-semibold flex items-center justify-center mt-0.5">1</span>
            <div>
                <p class="text-sm text-gray-300">{{ __('admin.claude_mcp_step1') }}</p>
                <p class="text-xs text-gray-500 mt-0.5">{{ __('admin.claude_mcp_step1_path') }}</p>
            </div>
        </div>

        {{-- Step 2 --}}
        <div class="flex gap-3 mb-5">
            <span class="flex-shrink-0 w-5 h-5 rounded-full bg-sky-500/10 text-sky-400 text-xs font-semibold flex items-center justify-center mt-0.5">2</span>
            <div class="w-full">
                <p class="text-sm text-gray-300 mb-3">{{ __('admin.claude_mcp_step2') }}</p>

                {{-- MCP Server URL --}}
                <div class="mb-3">
                    <label class="block text-xs text-gray-500 mb-1">{{ __('admin.claude_mcp_server_url') }}</label>
                    <div class="flex items-center gap-2">
                        <input type="text" readonly value="{{ $mcpUrl }}" id="mcp-url"
                               class="flex-1 bg-[#1a1a1a] border border-white/10 text-gray-300 text-xs rounded-md px-3 py-2 focus:outline-none select-all">
                        <button type="button" onclick="copyToClipboard('mcp-url', this)"
                                class="shrink-0 p-2 text-gray-500 hover:text-gray-300 transition-colors" title="{{ __('admin.copy') }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- OAuth Client ID --}}
                <div class="mb-3">
                    <label class="block text-xs text-gray-500 mb-1">{{ __('admin.claude_mcp_client_id') }}</label>
                    <div class="flex items-center gap-2">
                        <input type="text" readonly value="{{ $clientId }}" id="mcp-client-id"
                               class="flex-1 bg-[#1a1a1a] border border-white/10 text-gray-300 text-xs rounded-md px-3 py-2 focus:outline-none select-all">
                        <button type="button" onclick="copyToClipboard('mcp-client-id', this)"
                                class="shrink-0 p-2 text-gray-500 hover:text-gray-300 transition-colors" title="{{ __('admin.copy') }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- OAuth Secret --}}
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('admin.claude_mcp_client_secret') }}</label>
                    <div class="flex items-center gap-2">
                        <input type="password" readonly value="{{ $secret }}" id="mcp-secret"
                               class="flex-1 bg-[#1a1a1a] border border-white/10 text-gray-300 text-xs rounded-md px-3 py-2 focus:outline-none select-all">
                        <button type="button" onclick="toggleSecret(this)"
                                class="shrink-0 p-2 text-gray-500 hover:text-gray-300 transition-colors" title="{{ __('admin.show') }}"
                                data-target="mcp-secret">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                        <button type="button" onclick="copyToClipboard('mcp-secret', this)"
                                class="shrink-0 p-2 text-gray-500 hover:text-gray-300 transition-colors" title="{{ __('admin.copy') }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 3 --}}
        <div class="flex gap-3">
            <span class="flex-shrink-0 w-5 h-5 rounded-full bg-sky-500/10 text-sky-400 text-xs font-semibold flex items-center justify-center mt-0.5">3</span>
            <div>
                <p class="text-sm text-gray-300">{{ __('admin.claude_mcp_step3') }}</p>
                <p class="text-xs text-gray-500 mt-0.5">{{ __('admin.claude_mcp_step3_hint') }}</p>
            </div>
        </div>

    </div>

    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 text-sm text-gray-300">
        <h2 class="font-semibold text-white">{{ __('admin.mcp_api_key') }}</h2>
        <p class="text-xs text-gray-400 my-3">{{ __('security.keys_hint') }}</p>
        @if ($newApiKey)
            <div role="status" class="border border-sky-500/30 bg-sky-950 rounded-md p-3 mb-4">
                <p>{{ __('admin.mcp_api_key_generated_once') }}</p>
                <code class="block break-all font-mono mt-2 select-all">{{ $newApiKey }}</code>
            </div>
        @endif
        <form method="POST" action="{{ route('admin.claude-mcp.api-key.generate') }}" class="space-y-3">
            @csrf
            <label for="key-name" class="block">{{ __('security.key_name') }}</label>
            <input id="key-name" name="name" required maxlength="100" value="{{ old('name') }}" class="w-full bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2">
            <label for="key-scope" class="block">{{ __('security.scope') }}</label>
            <select id="key-scope" name="scope" class="w-full bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2">
                @foreach(['read-only', 'draft-only', 'full'] as $scope)<option value="{{ $scope }}">{{ __('security.scope_'.$scope) }}</option>@endforeach
            </select>
            <label for="key-expiry" class="block">{{ __('security.key_expiry') }}</label>
            <input id="key-expiry" name="expires_at" type="datetime-local" class="bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2">
            @foreach(['name', 'scope', 'expires_at'] as $field) @error($field)<p class="text-red-400 text-xs" role="alert">{{ $message }}</p>@enderror @endforeach
            <button type="submit" class="bg-sky-600 text-white px-3 py-2 rounded-md">{{ __('admin.mcp_api_key_generate') }}</button>
        </form>
        <h3 class="font-semibold text-white mt-6 mb-3">{{ __('security.active_keys') }}</h3>
        <ul class="space-y-3">
            @forelse($apiKeys as $key)
            <li class="border-b border-white/10 pb-3">
                <p>{{ $key->name }} · {{ __('security.scope_'.$key->scope) }}</p>
                <p class="text-xs text-gray-400">{{ __('security.key_expiry') }}: {{ $key->expires_at?->format('Y-m-d H:i') ?? __('security.never') }} · {{ __('admin.mcp_api_key_last_used_at') }}: {{ $key->last_used_at?->format('Y-m-d H:i') ?? '—' }}</p>
                @if($key->revoked_at)
                    <p class="text-gray-400">{{ __('security.revoked') }}</p>
                @else
                    <form method="POST" action="{{ route('admin.claude-mcp.api-keys.revoke', $key) }}" class="mt-2">
                        @csrf @method('DELETE')
                        <button type="submit" class="border border-red-500/30 text-red-300 px-3 py-2 rounded-md">{{ __('security.revoke') }}</button>
                    </form>
                @endif
            </li>
            @empty
                <li class="text-gray-400">{{ __('security.no_keys') }}</li>
            @endforelse
        </ul>
        @if($user->hasMcpApiKey())
            <form method="POST" action="{{ route('admin.claude-mcp.api-key.revoke') }}" class="mt-4">
                @csrf @method('DELETE')
                <button class="text-red-300 border border-red-500/30 px-3 py-2 rounded-md" type="submit">{{ __('admin.mcp_api_key_revoke') }}</button>
            </form>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
function copyToClipboard(inputId, btn) {
    const input = document.getElementById(inputId);
    navigator.clipboard.writeText(input.value).then(() => {
        const svg = btn.querySelector('svg');
        svg.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>';
        setTimeout(() => {
            svg.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>';
        }, 1500);
    });
}

function toggleSecret(btn) {
    const input = document.getElementById(btn.dataset.target);
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
}
</script>
@endpush
