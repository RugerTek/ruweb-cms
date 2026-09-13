{{-- Shared destructive-action confirmation. Opened through window.confirmAction(). --}}
<div id="confirm-modal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
     role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
    <div class="bg-[#141414] ring-1 ring-white/[0.08] rounded-xl shadow-2xl w-full max-w-sm">
        <div class="px-5 py-4 border-b border-white/[0.06]">
            <h2 id="confirm-modal-title" class="text-sm font-semibold text-white" data-default="{{ __('admin.confirm_title') }}">{{ __('admin.confirm_title') }}</h2>
        </div>

        <div class="px-5 py-4">
            <p id="confirm-modal-message" class="text-sm text-gray-400"></p>
        </div>

        <div class="flex justify-end gap-2 px-5 py-4 border-t border-white/[0.06]">
            <button type="button" id="confirm-modal-cancel"
                    data-default="{{ __('admin.btn_cancel') }}"
                    class="px-3 py-2 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors">
                {{ __('admin.btn_cancel') }}
            </button>
            <button type="button" id="confirm-modal-accept"
                    data-default="{{ __('admin.btn_delete') }}"
                    class="px-3 py-2 text-white text-sm font-medium rounded-md transition-colors">
                {{ __('admin.btn_delete') }}
            </button>
        </div>
    </div>
</div>
