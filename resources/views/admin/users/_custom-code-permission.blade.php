<div class="sm:col-span-2">
    <input type="hidden" name="can_manage_custom_code" value="0">
    <label class="flex items-center gap-2 text-sm text-gray-300">
        <input type="checkbox" name="can_manage_custom_code" value="1" @checked(old('can_manage_custom_code', $user->can_manage_custom_code ?? false))>
        {{ __('custom_code.permission') }}
    </label>
    <p class="text-xs text-gray-500 mt-1">{{ __('custom_code.permission_help') }}</p>
    @error('can_manage_custom_code') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
</div>
