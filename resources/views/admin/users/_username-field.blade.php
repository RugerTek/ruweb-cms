<div>
    <label for="username" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_username') }}</label>
    <input type="text" id="username" name="username" value="{{ old('username', $user?->username ?? '') }}"
           minlength="3" maxlength="32" pattern="[a-zA-Z0-9._-]{3,32}" autocomplete="username" autocapitalize="none" spellcheck="false"
           aria-describedby="username-help"
           class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
    <p id="username-help" class="text-xs text-gray-500 mt-1">{{ __('admin.username_help') }}</p>
    @error('username') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
</div>
