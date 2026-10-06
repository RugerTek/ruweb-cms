@extends('admin.layouts.app')

@section('title', __('admin.users'))

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.users') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.users_subtitle') }}</p>
    </div>

    <a href="{{ route('admin.users.create') }}" class="inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        {{ __('admin.new_user') }}
    </a>
</div>

<div class="mb-6">
    <form method="GET" class="flex gap-2">
        <input
            type="text"
            name="search"
            value="{{ request('search') }}"
            placeholder="{{ __('admin.search_user_placeholder') }}"
            class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
        >
        <button type="submit" class="px-3 py-2 bg-gray-800 border border-white/10 text-gray-400 text-sm rounded-md hover:bg-gray-700 transition-colors">
            {{ __('admin.search') }}
        </button>
    </form>
</div>

@if ($users->isEmpty())
    <div class="text-center py-20 text-gray-600">
        <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-4a4 4 0 11-8 0 4 4 0 018 0zm6 2a3 3 0 11-6 0 3 3 0 016 0zM9 8a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <p class="text-sm font-medium text-gray-500">{{ __('admin.no_users_yet') }}</p>
        <p class="text-sm text-gray-600 mt-1">{{ __('admin.no_users_hint') }}</p>
        <a href="{{ route('admin.users.create') }}" class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ __('admin.new_user') }}
        </a>
    </div>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.field_name') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden sm:table-cell">{{ __('admin.field_email') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_role') }}</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach ($users as $user)
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.users.edit', $user) }}" class="text-sm text-white hover:text-sky-400 transition-colors">
                                {{ $user->name }}
                            </a>
                            @if($user->username)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $user->username }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="text-sm text-gray-400">{{ $user->email }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $roleKey = 'admin.role_' . $user->role;
                                $roleLabel = __($roleKey) !== $roleKey ? __($roleKey) : ucfirst($user->role);
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium capitalize {{ $user->isAdmin() ? 'bg-sky-500/10 text-sky-400' : 'bg-gray-500/10 text-gray-400' }}">
                                {{ $roleLabel }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('admin.users.edit', $user) }}" class="text-gray-600 hover:text-gray-400 transition-colors" title="{{ __('admin.action_edit') }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </a>

                                @if (! auth()->user()->is($user))
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('{{ __('admin.confirm_delete_user') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-400/70 hover:text-red-300 transition-colors" title="{{ __('admin.btn_delete_user') }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-7 0h8"/>
                                            </svg>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6 flex items-center justify-between gap-4">
        <p class="text-xs text-gray-500">{{ __('admin.total_users') }}: {{ $totalCount }}</p>
        <div>
            {{ $users->links() }}
        </div>
    </div>
@endif
@endsection
