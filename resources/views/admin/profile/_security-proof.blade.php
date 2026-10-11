<input type="hidden" name="_profile_tab" value="security">
<div>
    <label for="{{ $prefix }}-password" class="block text-sm mb-1">{{ __('admin.field_current_password') }}</label>
    <input id="{{ $prefix }}-password" name="current_password" type="password" required autocomplete="current-password" class="w-full sm:max-w-sm bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2">
    @error('current_password') <p class="text-red-400 text-xs" role="alert">{{ $message }}</p> @enderror
</div>
@if($withCode)
<div>
    <label for="{{ $prefix }}-code" class="block text-sm mb-1">{{ __('security.code') }}</label>
    <input id="{{ $prefix }}-code" name="code" type="text" required autocomplete="one-time-code" class="w-full sm:max-w-sm bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2">
    @error('code') <p class="text-red-400 text-xs" role="alert">{{ $message }}</p> @enderror
</div>
@endif
