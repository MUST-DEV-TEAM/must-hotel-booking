document.addEventListener('DOMContentLoaded', function () {
    var paymentInputs = Array.prototype.slice.call(
        document.querySelectorAll('.must-confirmation-payment-option input[type="radio"][name="payment_method"]')
    );
    var ctaText = document.querySelector('.must-confirmation-submit span');

    if (!paymentInputs.length || !ctaText) {
        return;
    }

    var syncCtaLabel = function () {
        var selectedInput = paymentInputs.find(function (input) {
            return input.checked;
        });

        if (!selectedInput) {
            return;
        }

        var ctaLabel = selectedInput.getAttribute('data-cta-label');

        if (ctaLabel) {
            ctaText.textContent = ctaLabel;
        }
    };

    paymentInputs.forEach(function (input) {
        input.addEventListener('change', syncCtaLabel);
    });

    syncCtaLabel();
});
