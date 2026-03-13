(function () {
    'use strict';

    function sanitizePhoneValue(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function bindPhoneInput(input) {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        input.addEventListener('input', function () {
            var sanitized = sanitizePhoneValue(input.value);

            if (input.value !== sanitized) {
                input.value = sanitized;
            }
        });

        input.value = sanitizePhoneValue(input.value);
    }

    function initPhoneInputs() {
        document.querySelectorAll('input[name="phone_number"]').forEach(bindPhoneInput);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPhoneInputs);
    } else {
        initPhoneInputs();
    }
})();
