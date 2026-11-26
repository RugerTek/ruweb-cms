{{--
    Embedded payment form of an online provider (PaymentStart kind `iframe`; Bancard vPOS 2.0:
    Bancard.Checkout.createForm(container, process_id, options) from the provider script). The form itself sends the
    customer back to the order page; the outcome is trusted only from the provider's notification.
    Expects $paymentStart (App\Shop\Payments\PaymentStart, iframe).
--}}
<div class="mt-4">
    <p class="text-sm text-gray-600">{{ __('checkout.pay_with_card') }}</p>
    <div id="payment-iframe-container"
         class="mt-3 min-h-96 w-full overflow-hidden rounded-lg border border-gray-200 bg-white"
         data-payment-iframe
         data-process-id="{{ $paymentStart->processId }}"></div>
</div>
@push('scripts')
    <script src="{{ $paymentStart->scriptUrl }}"></script>
    <script>
        (function () {
            var container = document.getElementById('payment-iframe-container');
            if (!container || !window.Bancard || !window.Bancard.Checkout) {
                return;
            }
            window.Bancard.Checkout.createForm('payment-iframe-container', container.dataset.processId, {});
        })();
    </script>
@endpush
