(function () {
    'use strict';

    var config = window.MustPortalQuickBooking || {};
    var strings = config.strings || {};

    function isValidDateString(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
    }

    function formatDate(date) {
        var year = String(date.getFullYear());
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');

        return year + '-' + month + '-' + day;
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

    function normalizeDateList(value) {
        if (!Array.isArray(value)) {
            return [];
        }

        return value.filter(isValidDateString).map(String);
    }

    function firstAvailableDate(startDateString, disabledDates, maxDays) {
        if (!isValidDateString(startDateString)) {
            return '';
        }

        var date = new Date(startDateString + 'T00:00:00');

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        for (var index = 0; index < maxDays; index += 1) {
            var formatted = formatDate(date);

            if (disabledDates.indexOf(formatted) === -1) {
                return formatted;
            }

            date.setDate(date.getDate() + 1);
        }

        return '';
    }

    function setStatus(statusEl, message, state) {
        if (!statusEl) {
            return;
        }

        statusEl.textContent = String(message || '');
        statusEl.dataset.state = String(state || '');
    }

    function initQuickBookingForm(form) {
        var roomSelect = form.querySelector('[data-must-portal-room-select]');
        var checkinInput = form.querySelector('[data-must-portal-checkin]');
        var checkoutInput = form.querySelector('[data-must-portal-checkout]');
        var guestsInput = form.querySelector('input[name="guests"]');
        var checkinCalendar = form.querySelector('[data-must-portal-checkin-calendar]');
        var checkoutCalendar = form.querySelector('[data-must-portal-checkout-calendar]');
        var statusEl = form.querySelector('[data-must-portal-date-status]');

        if (!roomSelect || !checkinInput || !checkoutInput || !checkinCalendar || !checkoutCalendar || typeof window.flatpickr !== 'function') {
            return;
        }

        var state = {
            disabledCheckinDates: [],
            disabledCheckoutDates: [],
            loading: false
        };

        function isDisabledCheckin(date) {
            return state.disabledCheckinDates.indexOf(formatDate(date)) !== -1;
        }

        function isDisabledCheckout(date) {
            return state.disabledCheckoutDates.indexOf(formatDate(date)) !== -1;
        }

        function refreshCalendars() {
            if (checkinPicker) {
                checkinPicker.set('disable', [isDisabledCheckin]);
                checkinPicker.redraw();
            }

            if (checkoutPicker) {
                checkoutPicker.set('disable', [isDisabledCheckout]);
                checkoutPicker.redraw();
            }
        }

        function applyPayload(payload) {
            state.disabledCheckinDates = normalizeDateList(payload.disabled_checkin_dates);
            state.disabledCheckoutDates = normalizeDateList(payload.disabled_checkout_dates);
            refreshCalendars();

            if (isValidDateString(checkinInput.value) && state.disabledCheckinDates.indexOf(checkinInput.value) !== -1) {
                checkinPicker.clear();
                checkoutPicker.clear();
                checkinInput.value = '';
                checkoutInput.value = '';
            } else if (isValidDateString(checkinInput.value)) {
                var minCheckout = addDays(checkinInput.value, 1);

                if (!isValidDateString(checkoutInput.value) || checkoutInput.value <= checkinInput.value || state.disabledCheckoutDates.indexOf(checkoutInput.value) !== -1) {
                    checkoutPicker.setDate(firstAvailableDate(minCheckout, state.disabledCheckoutDates, 180), true, 'Y-m-d');
                }
            }

            setStatus(statusEl, strings.datesReady || 'Unavailable dates are marked on the calendars.', 'ready');
        }

        function fetchDisabledDates(checkin) {
            var roomId = parseInt(roomSelect.value || '0', 10);

            if (!roomId) {
                state.disabledCheckinDates = [];
                state.disabledCheckoutDates = [];
                refreshCalendars();
                setStatus(statusEl, strings.selectRoom || 'Select a room to load unavailable dates.', 'idle');
                return Promise.resolve();
            }

            var body = new URLSearchParams();
            body.set('action', String(config.disabledDatesAction || 'must_portal_quick_booking_disabled_dates'));
            body.set('nonce', String(config.disabledDatesNonce || ''));
            body.set('room_id', String(roomId));
            body.set('checkin', isValidDateString(checkin) ? checkin : '');
            body.set('guests', guestsInput ? String(Math.max(1, parseInt(guestsInput.value || '1', 10) || 1)) : '1');
            body.set('window_days', '180');

            state.loading = true;
            setStatus(statusEl, strings.loadingDates || 'Loading room availability...', 'loading');

            return window.fetch(String(config.ajaxUrl || ''), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('disabled-dates-request-failed');
                }

                return response.json();
            }).then(function (json) {
                if (!json || !json.success || !json.data) {
                    throw new Error('disabled-dates-response-invalid');
                }

                applyPayload(json.data);
            }).catch(function () {
                setStatus(statusEl, strings.datesError || 'Unable to load unavailable dates for this room.', 'error');
            }).finally(function () {
                state.loading = false;
            });
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
            disable: [isDisabledCheckout],
            onChange: function (selectedDates, dateStr) {
                checkoutInput.value = dateStr || '';
            }
        }));
        var checkinPicker = window.flatpickr(checkinInput, Object.assign({}, commonOptions, {
            appendTo: checkinCalendar,
            disable: [isDisabledCheckin],
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

                fetchDisabledDates(dateStr);
            }
        }));

        if (isValidDateString(checkinInput.value)) {
            checkoutPicker.set('minDate', addDays(checkinInput.value, 1));
        }

        roomSelect.addEventListener('change', function () {
            fetchDisabledDates(checkinInput.value);
        });

        if (guestsInput) {
            guestsInput.addEventListener('change', function () {
                fetchDisabledDates(checkinInput.value);
            });
        }

        fetchDisabledDates(checkinInput.value);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.must-portal-quick-booking-form').forEach(initQuickBookingForm);
    });
})();
