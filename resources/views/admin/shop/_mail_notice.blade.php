{{--
    «El envío de emails no está activo» (docs/shop/checkout.md#sin-email): the shop sells without email, so this only
    informs. Included by Settings > Shop, Payments and shipping and Orders; renders nothing while email is active
    (App\Services\Mail\MailSettings::active()).
--}}
@unless (app(\App\Services\Mail\MailSettings::class)->active())
    <div class="mb-6 rounded-xl p-4 text-sm ring-1 bg-amber-500/10 ring-amber-500/20 max-w-4xl" role="status" data-shop-mail-off>
        <p class="font-medium text-amber-300">{{ __('checkout.mail_off_title') }}</p>
        <p class="mt-1 text-gray-300">{{ __('checkout.mail_off_notice') }}</p>
        @if (auth()->user()?->isAdmin())
            <a href="{{ route('admin.email.index') }}" class="mt-2 inline-block text-sky-400 hover:text-sky-300">{{ __('checkout.mail_off_action') }}</a>
        @endif
    </div>
@endunless
