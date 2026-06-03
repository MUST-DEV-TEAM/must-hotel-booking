(function () {
    'use strict';

    var config = window.MustPortalQuickBooking || {};
    var strings = config.strings || {};

    function isValidDateString(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
    }

    function normalizeDateValue(value) {
        var raw = String(value || '').trim();
        var match;

        if (isValidDateString(raw)) {
            return raw;
        }

        match = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);

        if (match) {
            return match[3] + '-' + String(match[1]).padStart(2, '0') + '-' + String(match[2]).padStart(2, '0');
        }

        return '';
    }

    function formatDate(date) {
        return String(date.getFullYear()) + '-' +
            String(date.getMonth() + 1).padStart(2, '0') + '-' +
            String(date.getDate()).padStart(2, '0');
    }

    function addDays(dateString, days) {
        if (!isValidDateString(dateString)) {
            return '';
        }

        var date = new Date(dateString + 'T00:00:00');

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        date.setDate(date.getDate() + Number(days || 0));

        return formatDate(date);
    }

    function setStatus(statusEl, message, state) {
        if (!statusEl) {
            return;
        }

        statusEl.textContent = String(message || '');
        statusEl.dataset.state = String(state || '');
    }

    function initQuickBookingForm(form) {
        var checkinInput = form.querySelector('[data-must-portal-checkin]');
        var checkoutInput = form.querySelector('[data-must-portal-checkout]');
        var checkinCalendar = form.querySelector('[data-must-portal-checkin-calendar]');
        var checkoutCalendar = form.querySelector('[data-must-portal-checkout-calendar]');
        var statusEl = form.querySelector('[data-must-portal-date-status]');

        if (!checkinInput || !checkoutInput || !checkinCalendar || !checkoutCalendar || typeof window.flatpickr !== 'function') {
            return;
        }

        var today = isValidDateString(config.today) ? String(config.today) : formatDate(new Date());
        var commonOptions = {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'm/d/Y',
            minDate: today,
            inline: true,
            monthSelectorType: 'static'
        };
        var checkoutPicker = window.flatpickr(checkoutInput, Object.assign({}, commonOptions, {
            appendTo: checkoutCalendar,
            onChange: function (selectedDates, dateStr) {
                checkoutInput.value = dateStr || '';
            }
        }));

        window.flatpickr(checkinInput, Object.assign({}, commonOptions, {
            appendTo: checkinCalendar,
            onChange: function (selectedDates, dateStr) {
                checkinInput.value = dateStr || '';

                if (!dateStr) {
                    return;
                }

                var minCheckout = addDays(dateStr, 1);
                checkoutPicker.set('minDate', minCheckout || today);

                if (!isValidDateString(checkoutInput.value) || checkoutInput.value <= dateStr) {
                    checkoutPicker.setDate(minCheckout, true, 'Y-m-d');
                }
            }
        }));

        if (isValidDateString(checkinInput.value)) {
            checkoutPicker.set('minDate', addDays(checkinInput.value, 1));
        }

        form.addEventListener('submit', function (event) {
            var checkin = normalizeDateValue(checkinInput.value);
            var checkout = normalizeDateValue(checkoutInput.value);

            checkinInput.value = checkin;
            checkoutInput.value = checkout;

            if (!isValidDateString(checkin) || !isValidDateString(checkout) || checkout <= checkin) {
                event.preventDefault();
                setStatus(statusEl, strings.invalidDates || 'Please provide valid check-in and check-out dates.', 'error');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.must-portal-quick-booking-form').forEach(initQuickBookingForm);
    });
})();
