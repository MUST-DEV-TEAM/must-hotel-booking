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
        var estimateEl = form.querySelector('[data-must-portal-estimate-total]');
        var roomOptionsEl = form.querySelector('[data-must-portal-room-options]');

        if (!roomSelect || !checkinInput || !checkoutInput || !checkinCalendar || !checkoutCalendar || typeof window.flatpickr !== 'function') {
            return;
        }

        var state = {
            disabledCheckinDates: [],
            disabledCheckoutDates: [],
            loading: false
        };
        var previewTimer = 0;
        var roomsTimer = 0;
        var initialRoomOptions = Array.prototype.slice.call(roomSelect.options).map(function (option) {
            return {
                value: option.value,
                text: option.textContent
            };
        });

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
            schedulePreview();
        }

        function formatMoney(amount, currency) {
            var numeric = Number(amount || 0);
            var formatted;

            try {
                formatted = numeric.toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            } catch (error) {
                formatted = numeric.toFixed(2);
            }

            return formatted + ' ' + String(currency || config.currency || '');
        }

        function updateEstimateText(text) {
            if (estimateEl) {
                estimateEl.textContent = String(text || '');
            }
        }

        function resetRoomOptions() {
            var selected = roomSelect.value;
            roomSelect.innerHTML = '';
            initialRoomOptions.forEach(function (option) {
                var node = document.createElement('option');
                node.value = option.value;
                node.textContent = option.text;
                roomSelect.appendChild(node);
            });
            roomSelect.value = selected;
        }

        function renderAvailableRooms(rooms) {
            var selected = roomSelect.value;

            if (!Array.isArray(rooms) || rooms.length === 0) {
                if (roomOptionsEl) {
                    roomOptionsEl.innerHTML = '';
                }
                resetRoomOptions();
                return;
            }

            roomSelect.innerHTML = '';

            var placeholder = document.createElement('option');
            placeholder.value = '0';
            placeholder.textContent = strings.selectRoomOption || 'Select a room';
            roomSelect.appendChild(placeholder);

            rooms.forEach(function (room) {
                var option = document.createElement('option');
                option.value = String(room.id || 0);
                option.textContent = String(room.name || ('#' + option.value)) + (room.formatted_total ? ' - ' + String(room.formatted_total) : '');
                roomSelect.appendChild(option);
            });

            if (selected && roomSelect.querySelector('option[value="' + selected.replace(/"/g, '\\"') + '"]')) {
                roomSelect.value = selected;
            }

            if (roomOptionsEl) {
                roomOptionsEl.innerHTML = rooms.map(function (room) {
                    return '<button type="button" class="must-portal-room-option" data-room-id="' + String(room.id || 0) + '">' +
                        '<strong>' + String(room.name || '') + '</strong>' +
                        '<span>' + String(room.formatted_total || '') + '</span>' +
                        '</button>';
                }).join('');
            }
        }

        function fetchAvailableRooms() {
            var checkin = normalizeDateValue(checkinInput.value);
            var checkout = normalizeDateValue(checkoutInput.value);
            var guests = guestsInput ? String(Math.max(1, parseInt(guestsInput.value || '1', 10) || 1)) : '1';
            var body;

            checkinInput.value = checkin;
            checkoutInput.value = checkout;

            if (!isValidDateString(checkin) || !isValidDateString(checkout) || checkout <= checkin) {
                return Promise.resolve();
            }

            body = new URLSearchParams();
            body.set('action', String(config.availableRoomsAction || 'must_portal_quick_booking_available_rooms'));
            body.set('nonce', String(config.availableRoomsNonce || ''));
            body.set('checkin', checkin);
            body.set('checkout', checkout);
            body.set('guests', guests);

            return window.fetch(String(config.ajaxUrl || ''), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('available-rooms-request-failed');
                }

                return response.json();
            }).then(function (json) {
                if (!json || !json.success || !json.data) {
                    throw new Error('available-rooms-response-invalid');
                }

                renderAvailableRooms(json.data.rooms || []);
            }).catch(function () {
                if (roomOptionsEl) {
                    roomOptionsEl.innerHTML = '';
                }
            });
        }

        function scheduleAvailableRooms() {
            window.clearTimeout(roomsTimer);
            roomsTimer = window.setTimeout(fetchAvailableRooms, 250);
        }

        function previewBooking() {
            var roomId = parseInt(roomSelect.value || '0', 10);
            var checkin = normalizeDateValue(checkinInput.value);
            var checkout = normalizeDateValue(checkoutInput.value);
            var guests = guestsInput ? String(Math.max(1, parseInt(guestsInput.value || '1', 10) || 1)) : '1';
            var body;

            checkinInput.value = checkin;
            checkoutInput.value = checkout;

            if (!roomId || !isValidDateString(checkin) || !isValidDateString(checkout) || checkout <= checkin) {
                updateEstimateText(formatMoney(0, config.currency || ''));
                setStatus(statusEl, strings.invalidDates || 'Please provide valid check-in and check-out dates.', 'error');
                return Promise.resolve();
            }

            body = new URLSearchParams();
            body.set('action', String(config.previewAction || 'must_portal_quick_booking_preview'));
            body.set('nonce', String(config.previewNonce || ''));
            body.set('room_id', String(roomId));
            body.set('checkin', checkin);
            body.set('checkout', checkout);
            body.set('guests', guests);
            body.set('guest_name', String((form.querySelector('[name="guest_name"]') || {}).value || 'Guest'));
            body.set('email', String((form.querySelector('[name="email"]') || {}).value || 'guest@example.com'));
            body.set('phone', String((form.querySelector('[name="phone"]') || {}).value || ''));
            body.set('booking_source', String((form.querySelector('[name="booking_source"]') || {}).value || 'walk_in'));

            setStatus(statusEl, strings.loadingPreview || 'Checking availability and price...', 'loading');

            return window.fetch(String(config.ajaxUrl || ''), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('preview-request-failed');
                }

                return response.json();
            }).then(function (json) {
                var total;

                if (!json || !json.success || !json.data) {
                    throw new Error('preview-response-invalid');
                }

                total = json.data.formatted_total || formatMoney(json.data.total_price, json.data.currency);
                updateEstimateText(total);
                setStatus(statusEl, (strings.available || 'Room available. Total: %s').replace('%s', total), 'ready');
            }).catch(function () {
                updateEstimateText(formatMoney(0, config.currency || ''));
                setStatus(statusEl, strings.unavailable || 'This room is not available for the selected dates.', 'error');
            });
        }

        function schedulePreview() {
            window.clearTimeout(previewTimer);
            previewTimer = window.setTimeout(previewBooking, 250);
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
                scheduleAvailableRooms();
                schedulePreview();
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
                scheduleAvailableRooms();
                schedulePreview();
            }
        }));

        if (isValidDateString(checkinInput.value)) {
            checkoutPicker.set('minDate', addDays(checkinInput.value, 1));
        }

        roomSelect.addEventListener('change', function () {
            fetchDisabledDates(checkinInput.value);
            schedulePreview();
        });

        if (guestsInput) {
            guestsInput.addEventListener('change', function () {
                fetchDisabledDates(checkinInput.value);
                scheduleAvailableRooms();
                schedulePreview();
            });
        }

        if (roomOptionsEl) {
            roomOptionsEl.addEventListener('click', function (event) {
                var button = event.target && event.target.closest ? event.target.closest('[data-room-id]') : null;

                if (!button) {
                    return;
                }

                roomSelect.value = String(button.getAttribute('data-room-id') || '0');
                fetchDisabledDates(checkinInput.value);
                schedulePreview();
            });
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

        fetchDisabledDates(checkinInput.value);
        scheduleAvailableRooms();
        schedulePreview();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.must-portal-quick-booking-form').forEach(initQuickBookingForm);
    });
})();
