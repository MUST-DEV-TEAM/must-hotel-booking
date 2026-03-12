(function () {
    'use strict';

    var config = window.mustHotelBookingBookingPage || null;

    if (!config) {
        return;
    }

    var state = {
        availabilityRequestToken: 0,
        disabledDatesRequestToken: 0,
        refreshTimer: null,
        checkinPicker: null,
        checkoutPicker: null,
        currentStep: 1,
        previewCheckout: '',
        disabledCheckinDates: [],
        disabledCheckoutDates: []
    };

    var monthNames = [
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December'
    ];

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

    function parseDateString(value) {
        if (!isValidDateString(value)) {
            return null;
        }

        var date = new Date(value + 'T00:00:00');

        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return date;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatTemplate(template, value) {
        var output = String(template || '');

        if (output.indexOf('%d') !== -1) {
            return output.replace('%d', String(value));
        }

        if (output.indexOf('%s') !== -1) {
            return output.replace('%s', String(value));
        }

        return output + ' ' + String(value);
    }

    function formatTwoValueTemplate(template, firstValue, secondValue) {
        var output = String(template || '');

        if (output.indexOf('%1$s') !== -1 || output.indexOf('%2$d') !== -1 || output.indexOf('%2$s') !== -1) {
            return output
                .replace('%1$s', String(firstValue))
                .replace('%2$d', String(secondValue))
                .replace('%2$s', String(secondValue));
        }

        return String(firstValue) + ' / ' + String(secondValue);
    }

    function formatPrice(value) {
        var amount = Number(value);
        var symbol = String(config.currencySymbol || '').trim();

        if (!Number.isFinite(amount)) {
            amount = 0;
        }

        var formattedAmount = amount.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        return symbol !== '' ? formattedAmount + ' ' + symbol : formattedAmount;
    }

    function getMonthFloorDate() {
        var today = new Date();
        return new Date(today.getFullYear(), today.getMonth(), 1);
    }

    function populateSelectOptions(selectEl, options, selectedValue) {
        if (!selectEl) {
            return;
        }

        var normalizedSelected = String(selectedValue);
        var html = options.map(function (item) {
            var selected = String(item.value) === normalizedSelected ? ' selected' : '';
            var disabled = item && item.disabled ? ' disabled' : '';
            return '<option value="' + escapeHtml(item.value) + '"' + selected + disabled + '>' + escapeHtml(item.label) + '</option>';
        }).join('');

        if (selectEl.innerHTML !== html) {
            selectEl.innerHTML = html;
        }

        selectEl.value = normalizedSelected;
    }

    function getYearOptions(currentYear, floorYear) {
        var minYear = Number(floorYear);
        var startYear = minYear - 1;
        var maxYear = floorYear + 10;
        var selectedYear = Number(currentYear);
        var options = [];

        if (Number.isFinite(selectedYear) && selectedYear > maxYear) {
            maxYear = selectedYear;
        }

        if (startYear < 1970) {
            startYear = 1970;
        }

        for (var year = startYear; year <= maxYear; year++) {
            options.push({
                value: String(year),
                label: String(year),
                disabled: year < minYear
            });
        }

        return options;
    }

    function getHomeUrl() {
        var homeUrl = String(config.homeUrl || '').trim();

        if (homeUrl !== '') {
            return homeUrl;
        }

        return '/';
    }

    function isCalendarPageMode() {
        return String(config.pageMode || 'calendar') === 'calendar';
    }

    function getStepHeadingText(step) {
        var strings = config.strings || {};

        if (Number(step) > 1) {
            return String(strings.availableAccommodationHeading || 'Available Accommodation');
        }

        return String(strings.selectDatesHeading || 'Select your dates');
    }

    function setCurrentStep(step, resultsEl) {
        var nextStep = parseInt(String(step || '1'), 10);
        var formEl = document.getElementById('must-booking-search-form');
        var headingEl = document.getElementById('must-booking-step-heading');
        var pageEl = document.querySelector('.must-hotel-booking-page-booking');

        if (!Number.isFinite(nextStep) || nextStep < 1) {
            nextStep = 1;
        }

        if (nextStep > 4) {
            nextStep = 4;
        }

        state.currentStep = nextStep;

        if (pageEl) {
            pageEl.classList.toggle('is-step-1', nextStep === 1);
            pageEl.classList.toggle('is-step-2', nextStep === 2);
        }

        if (headingEl) {
            headingEl.textContent = getStepHeadingText(nextStep);
        }

        if (formEl) {
            formEl.style.display = nextStep === 1 ? '' : 'none';
        }

        var stepElements = document.querySelectorAll('.must-booking-stepper-step[data-step]');

        stepElements.forEach(function (stepElement) {
            var stepValue = parseInt(String(stepElement.getAttribute('data-step') || ''), 10);
            stepElement.classList.toggle('is-active', stepValue === nextStep);
        });

        if (resultsEl) {
            resultsEl.style.display = nextStep > 1 ? '' : 'none';
        }
    }

    function redirectToAccommodation(context) {
        var baseUrl = String(config.accommodationUrl || config.bookingUrl || window.location.href);
        var params = new URLSearchParams();

        params.append('checkin', String(context.checkin || ''));
        params.append('checkout', String(context.checkout || ''));
        params.append('guests', String(context.guests || 1));
        params.append('accommodation_type', String(context.accommodationType || getAccommodationTypeValue()));

        window.location.href = baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + params.toString();
    }

    function getAccommodationTypeLabel(accommodationTypeSelect) {
        var selectEl = accommodationTypeSelect || document.getElementById('must-booking-accommodation-type');

        if (!selectEl) {
            return 'Standard Rooms';
        }

        var selectedIndex = typeof selectEl.selectedIndex === 'number' ? selectEl.selectedIndex : -1;

        if (selectedIndex >= 0 && selectEl.options && selectEl.options[selectedIndex]) {
            return String(selectEl.options[selectedIndex].text || 'Standard Rooms');
        }

        return String(selectEl.value || 'Standard Rooms');
    }

    function getAccommodationTypeValue(accommodationTypeSelect) {
        var fallback = String(
            (config.initial && config.initial.accommodationType) ||
            config.defaultAccommodationType ||
            'standard-rooms'
        );
        var selectEl = accommodationTypeSelect || document.getElementById('must-booking-accommodation-type');

        if (!selectEl) {
            return fallback;
        }

        return String(selectEl.value || fallback);
    }

    function getMaxGuestsLimit() {
        var parsed = parseInt(String(config.maxGuests || '5'), 10);

        if (!Number.isFinite(parsed) || parsed < 1) {
            return 5;
        }

        return parsed;
    }

    function getResultsDateRangeText(context) {
        var strings = config.strings || {};
        var checkinDate = parseDateString(context.checkin);
        var checkoutDate = parseDateString(context.checkout);

        if (!checkinDate || !checkoutDate) {
            return String(strings.selectDatesSummary || 'Select dates');
        }

        return [
            String(checkinDate.getDate()).padStart(2, '0'),
            monthNames[checkinDate.getMonth()],
            '-',
            String(checkoutDate.getDate()).padStart(2, '0'),
            monthNames[checkoutDate.getMonth()],
            checkoutDate.getFullYear()
        ].join(' ');
    }

    function getResultsSelectionSummary(context, accommodationTypeSelect) {
        var label = getAccommodationTypeLabel(accommodationTypeSelect);
        var template = (config.strings && config.strings.selectionSummaryFormat) || '%1$s / %2$d Guests';

        return formatTwoValueTemplate(template, label, context.guests);
    }

    function updateResultsSummary(context, accommodationTypeSelect) {
        var dateRangeEl = document.getElementById('must-booking-results-date-range');
        var selectionSummaryEl = document.getElementById('must-booking-results-selection-summary');

        if (dateRangeEl) {
            dateRangeEl.textContent = getResultsDateRangeText(context);
        }

        if (selectionSummaryEl) {
            selectionSummaryEl.textContent = getResultsSelectionSummary(context, accommodationTypeSelect);
        }
    }

    function getContext(checkinInput, checkoutInput, guestsInput, accommodationTypeSelect) {
        var checkin = String(checkinInput.value || '').trim();
        var checkout = String(checkoutInput.value || '').trim();
        var guests = parseInt(String(guestsInput.value || '1'), 10);
        var maxGuests = getMaxGuestsLimit();

        if (!Number.isFinite(guests) || guests < 1) {
            guests = 1;
        }

        if (guests > maxGuests) {
            guests = maxGuests;
        }

        return {
            checkin: checkin,
            checkout: checkout,
            guests: guests,
            accommodationType: getAccommodationTypeValue(accommodationTypeSelect)
        };
    }

    function isValidRange(context) {
        return (
            isValidDateString(context.checkin) &&
            isValidDateString(context.checkout) &&
            context.checkin < context.checkout
        );
    }

    function setLoading(loadingEl, isLoading) {
        if (!loadingEl) {
            return;
        }

        if (!isLoading) {
            loadingEl.style.display = 'none';
            loadingEl.textContent = '';
            return;
        }

        loadingEl.style.display = '';
        loadingEl.textContent = (config.strings && config.strings.loading) || 'Loading...';
    }

    function setMessage(messagesEl, message, type) {
        if (!messagesEl) {
            return;
        }

        if (!message) {
            messagesEl.innerHTML = '';
            return;
        }

        var cssClass = type === 'error' ? 'must-booking-message must-booking-message-error' : 'must-booking-message';
        messagesEl.innerHTML = '<p class="' + cssClass + '">' + escapeHtml(message) + '</p>';
    }

    function syncHiddenSelectionFields(context) {
        var checkinFields = document.querySelectorAll('.must-booking-hidden-checkin');
        var checkoutFields = document.querySelectorAll('.must-booking-hidden-checkout');
        var guestsFields = document.querySelectorAll('.must-booking-hidden-guests');
        var accommodationTypeFields = document.querySelectorAll('.must-booking-hidden-accommodation-type');

        checkinFields.forEach(function (field) {
            field.value = context.checkin;
        });

        checkoutFields.forEach(function (field) {
            field.value = context.checkout;
        });

        guestsFields.forEach(function (field) {
            field.value = String(context.guests);
        });

        accommodationTypeFields.forEach(function (field) {
            field.value = String(context.accommodationType || '');
        });
    }

    function renderRooms(roomListEl, noRoomsEl, resultsEl, rooms, context) {
        if (!roomListEl || !noRoomsEl || !resultsEl) {
            return;
        }

        if (!Array.isArray(rooms) || rooms.length === 0) {
            roomListEl.innerHTML = '';
            noRoomsEl.style.display = '';
            resultsEl.style.display = '';
            return;
        }

        var strings = config.strings || {};
        var bookingUrl = String(config.bookingUrl || window.location.href);
        var nonce = String(config.selectRoomNonce || '');
        var arrowIconUrl = String(config.arrowIconUrl || '');
        var bedIconUrl = String(config.bedIconUrl || '');
        var html = rooms.map(function (room) {
            var roomId = Number(room.id || 0);
            var roomName = String(room.name || strings.roomLabel || 'Room');
            var roomDescription = String(room.description || '');
            var maxGuests = Number(room.max_guests || 0);
            var roomSize = String(room.room_size || '');
            var estimatedTotal = room.dynamic_total_price !== null && room.dynamic_total_price !== undefined
                ? Number(room.dynamic_total_price)
                : (
                    room.price_preview_total !== null && room.price_preview_total !== undefined
                        ? Number(room.price_preview_total)
                        : null
                );
            var detailsUrl = String(room.details_url || '');
            var primaryImageUrl = String(room.primary_image_url || '');
            var galleryImages = Array.isArray(room.gallery_images) ? room.gallery_images.slice(0, 3) : [];
            var metaParts = [];

            if (maxGuests > 0) {
                metaParts.push('<span>' + escapeHtml(formatTemplate(strings.capacityFormat || '%d Guests', maxGuests)) + '</span>');
            }

            if (roomSize !== '') {
                metaParts.push('<span>' + escapeHtml(roomSize) + '</span>');
            }

            if (estimatedTotal !== null && Number.isFinite(estimatedTotal)) {
                metaParts.push('<span>' + escapeHtml(formatTemplate(strings.estimatedTotalFormat || 'Estimated Total: %s', formatPrice(estimatedTotal))) + '</span>');
            }

            var metaHtml = metaParts.length > 0
                ? '<div class="must-booking-room-meta">' + metaParts.join('') + '</div>'
                : '';
            var mediaHtml = primaryImageUrl !== ''
                ? '<div class="must-booking-room-media"><img src="' + escapeHtml(primaryImageUrl) + '" alt="' + escapeHtml(roomName) + '" loading="lazy" /></div>'
                : '<div class="must-booking-room-media"><div class="must-booking-room-media-placeholder">' + escapeHtml(strings.noImage || 'Add room image in admin') + '</div></div>';
            var thumbsHtml = galleryImages.map(function (imageUrl) {
                return '<span class="must-booking-room-thumb"><img src="' + escapeHtml(String(imageUrl || '')) + '" alt="" loading="lazy" /></span>';
            }).join('');

            while (galleryImages.length < 3) {
                thumbsHtml += '<span class="must-booking-room-thumb is-placeholder" aria-hidden="true"></span>';
                galleryImages.push('');
            }

            var arrowIconHtml = arrowIconUrl !== ''
                ? '<img src="' + escapeHtml(arrowIconUrl) + '" alt="" aria-hidden="true" />'
                : '';
            var bedIconHtml = bedIconUrl !== ''
                ? '<img src="' + escapeHtml(bedIconUrl) + '" alt="" aria-hidden="true" />'
                : '';
            var descriptionHtml = roomDescription !== ''
                ? '<p class="must-booking-room-description">' + escapeHtml(roomDescription) + '</p>'
                : '';
            var detailsLinkHtml = detailsUrl !== ''
                ? (
                    '<a class="must-booking-room-details" href="' + escapeHtml(detailsUrl) + '">' +
                        '<span>' + escapeHtml(strings.additionalDetails || 'Additional Details') + '</span>' +
                        bedIconHtml +
                    '</a>'
                )
                : '';

            return (
                '<article class="must-hotel-booking-room-card">' +
                    mediaHtml +
                    '<div class="must-booking-room-content">' +
                        '<div class="must-booking-room-header">' +
                            '<h3>' + escapeHtml(roomName) + '</h3>' +
                            descriptionHtml +
                            metaHtml +
                        '</div>' +
                        '<div class="must-booking-room-thumbs">' + thumbsHtml + '</div>' +
                        '<div class="must-booking-room-actions">' +
                            '<form class="must-hotel-booking-select-room-form" method="post" action="' + escapeHtml(bookingUrl) + '">' +
                                '<input type="hidden" name="must_booking_nonce" value="' + escapeHtml(nonce) + '" />' +
                                '<input type="hidden" name="must_booking_action" value="select_room" />' +
                                '<input type="hidden" name="room_id" value="' + escapeHtml(roomId) + '" />' +
                                '<input class="must-booking-hidden-checkin" type="hidden" name="checkin" value="' + escapeHtml(context.checkin) + '" />' +
                                '<input class="must-booking-hidden-checkout" type="hidden" name="checkout" value="' + escapeHtml(context.checkout) + '" />' +
                                '<input class="must-booking-hidden-guests" type="hidden" name="guests" value="' + escapeHtml(context.guests) + '" />' +
                                '<input class="must-booking-hidden-accommodation-type" type="hidden" name="accommodation_type" value="' + escapeHtml(context.accommodationType || '') + '" />' +
                                '<button type="submit" class="must-booking-room-book-button">' +
                                    '<span>' + escapeHtml(strings.bookNow || 'Book Now') + '</span>' +
                                    arrowIconHtml +
                                '</button>' +
                            '</form>' +
                            detailsLinkHtml +
                        '</div>' +
                    '</div>' +
                '</article>'
            );
        }).join('');

        roomListEl.innerHTML = html;
        noRoomsEl.style.display = 'none';
        resultsEl.style.display = '';
    }

    function setSummaryDate(dayEl, monthEl, value) {
        if (!dayEl || !monthEl) {
            return;
        }

        var date = parseDateString(value);

        if (!date) {
            dayEl.textContent = '--';
            monthEl.textContent = '--';
            return;
        }

        dayEl.textContent = String(date.getDate()).padStart(2, '0');
        monthEl.textContent = monthNames[date.getMonth()];
    }

    function updateSummary(context) {
        var arrivalDayEl = document.getElementById('must-booking-arrival-day');
        var arrivalMonthEl = document.getElementById('must-booking-arrival-month');
        var departureDayEl = document.getElementById('must-booking-departure-day');
        var departureMonthEl = document.getElementById('must-booking-departure-month');

        setSummaryDate(arrivalDayEl, arrivalMonthEl, context.checkin);
        setSummaryDate(departureDayEl, departureMonthEl, context.checkout);
    }

    function updateCalendarMeta(picker, monthId, yearId) {
        if (!picker) {
            return;
        }

        var monthEl = document.getElementById(monthId);
        var yearEl = document.getElementById(yearId);

        if (!monthEl || !yearEl) {
            return;
        }

        var floorDate = getMonthFloorDate();
        var checkinInput = document.getElementById('must-booking-checkin');
        var minDateString = floorDate.getFullYear() + '-' + String(floorDate.getMonth() + 1).padStart(2, '0') + '-01';

        if (monthId === 'must-booking-checkout-month' && checkinInput && String(checkinInput.value || '') !== '') {
            minDateString = addDays(String(checkinInput.value), 1);
        }

        var minDate = parseDateString(minDateString);
        var minYear = minDate ? minDate.getFullYear() : floorDate.getFullYear();
        var minMonth = minDate && picker.currentYear === minYear ? minDate.getMonth() : 0;

        var monthOptions = monthNames.map(function (label, index) {
            return {
                value: String(index),
                label: label,
                disabled: picker.currentYear === minYear && index < minMonth
            };
        });

        populateSelectOptions(monthEl, monthOptions, String(picker.currentMonth || 0));
        populateSelectOptions(yearEl, getYearOptions(picker.currentYear || minYear, minYear), String(picker.currentYear || ''));
    }

    function updateCalendarShiftState() {
        var previousButton = document.getElementById('must-booking-cal-prev');

        if (!previousButton || !state.checkinPicker) {
            return;
        }

        var floorDate = getMonthFloorDate();
        var checkinMonthDate = new Date(state.checkinPicker.currentYear, state.checkinPicker.currentMonth, 1);
        var canMovePrev = checkinMonthDate.getTime() > floorDate.getTime();

        previousButton.disabled = !canMovePrev;
        previousButton.classList.toggle('is-disabled', !canMovePrev);
    }

    function updateRangeHighlights(checkinValue, checkoutValue, previewCheckoutValue) {
        var startDate = parseDateString(checkinValue);
        var endDate = parseDateString(previewCheckoutValue || checkoutValue);
        var start = startDate ? formatDate(startDate) : '';
        var end = endDate ? formatDate(endDate) : '';

        [state.checkinPicker, state.checkoutPicker].forEach(function (picker) {
            if (!picker || !picker.calendarContainer) {
                return;
            }

            var dayElements = picker.calendarContainer.querySelectorAll('.flatpickr-day');

            dayElements.forEach(function (dayElement) {
                dayElement.classList.remove('must-booking-day-in-range');
                dayElement.classList.remove('must-booking-day-start');
                dayElement.classList.remove('must-booking-day-end');

                if (!dayElement.dateObj) {
                    return;
                }

                var current = formatDate(dayElement.dateObj);

                if (start && current === start) {
                    dayElement.classList.add('must-booking-day-start');
                }

                if (end && current === end) {
                    dayElement.classList.add('must-booking-day-end');
                }

                if (!start || !end || end <= start) {
                    return;
                }

                if (current > start && current < end) {
                    dayElement.classList.add('must-booking-day-in-range');
                }
            });
        });
    }

    function syncUnavailableDayClasses(picker, unavailableDates) {
        if (!picker || !picker.calendarContainer) {
            return;
        }

        var dates = Array.isArray(unavailableDates) ? unavailableDates : [];
        var dayElements = picker.calendarContainer.querySelectorAll('.flatpickr-day');

        dayElements.forEach(function (dayElement) {
            dayElement.classList.remove('must-booking-day-unavailable');

            if (!dayElement.dateObj) {
                return;
            }

            if (dates.indexOf(formatDate(dayElement.dateObj)) !== -1) {
                dayElement.classList.add('must-booking-day-unavailable');
            }
        });
    }

    function refreshUnavailableDayClasses() {
        syncUnavailableDayClasses(state.checkinPicker, state.disabledCheckinDates);
        syncUnavailableDayClasses(state.checkoutPicker, state.disabledCheckoutDates);
    }

    function applyDisabledDatesToPickers(disabledCheckinDates, disabledCheckoutDates, checkinInput, checkoutInput) {
        var checkinDates = Array.isArray(disabledCheckinDates) ? disabledCheckinDates : [];
        var checkoutDates = Array.isArray(disabledCheckoutDates) ? disabledCheckoutDates : [];
        var currentCheckin = String(checkinInput.value || '').trim();

        state.disabledCheckinDates = checkinDates.slice();
        state.disabledCheckoutDates = checkoutDates.slice();

        if (state.checkinPicker) {
            state.checkinPicker.set('disable', checkinDates);

            if (currentCheckin && checkinDates.indexOf(currentCheckin) !== -1) {
                state.checkinPicker.clear();
                checkinInput.value = '';
                currentCheckin = '';
            }
        }

        if (!state.checkoutPicker) {
            return;
        }

        var minCheckout = currentCheckin ? addDays(currentCheckin, 1) : (config.today || 'today');
        state.checkoutPicker.set('minDate', minCheckout || (config.today || 'today'));
        state.checkoutPicker.set('disable', checkoutDates);

        var currentCheckout = String(checkoutInput.value || '').trim();

        if (
            currentCheckout &&
            (
                (minCheckout && currentCheckout <= currentCheckin) ||
                checkoutDates.indexOf(currentCheckout) !== -1
            )
        ) {
            state.checkoutPicker.clear();
            checkoutInput.value = '';
        }

        refreshUnavailableDayClasses();
    }

    function fetchDisabledDates(context) {
        if (!config.ajaxUrl || !config.disabledDatesAction) {
            return Promise.resolve();
        }

        var params = new URLSearchParams();
        params.append('action', String(config.disabledDatesAction));
        params.append('guests', String(context.guests));
        params.append('accommodation_type', String(context.accommodationType || getAccommodationTypeValue()));
        params.append('window_days', String(config.windowDays || 180));

        if (isValidDateString(context.checkin)) {
            params.append('checkin', context.checkin);
        }

        var requestToken = ++state.disabledDatesRequestToken;

        return fetch(String(config.ajaxUrl) + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('disabled-dates-request-failed');
            }

            return response.json();
        }).then(function (payload) {
            if (requestToken !== state.disabledDatesRequestToken) {
                return;
            }

            if (!payload || payload.success !== true || !payload.data) {
                throw new Error('disabled-dates-response-invalid');
            }

            var data = payload.data;
            var disabledCheckinDates = Array.isArray(data.disabled_checkin_dates) ? data.disabled_checkin_dates : [];
            var disabledCheckoutDates = Array.isArray(data.disabled_checkout_dates) ? data.disabled_checkout_dates : [];
            var checkinInput = document.getElementById('must-booking-checkin');
            var checkoutInput = document.getElementById('must-booking-checkout');

            if (!checkinInput || !checkoutInput) {
                return;
            }

            applyDisabledDatesToPickers(disabledCheckinDates, disabledCheckoutDates, checkinInput, checkoutInput);
            var guestsInput = document.getElementById('must-booking-guests');

            if (guestsInput) {
                updateSummary(getContext(checkinInput, checkoutInput, guestsInput));
                updateResultsSummary(getContext(checkinInput, checkoutInput, guestsInput), document.getElementById('must-booking-accommodation-type'));
            } else {
                var fallbackContext = {
                    checkin: checkinInput.value || '',
                    checkout: checkoutInput.value || '',
                    guests: 1,
                    accommodationType: getAccommodationTypeValue()
                };

                updateSummary(fallbackContext);
                updateResultsSummary(fallbackContext, document.getElementById('must-booking-accommodation-type'));
            }

            updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
        }).catch(function () {
            return null;
        });
    }

    function fetchAvailability(context, roomListEl, noRoomsEl, resultsEl, loadingEl, messagesEl) {
        if (!config.ajaxUrl || !config.availabilityAction) {
            return;
        }

        if (!isValidRange(context)) {
            if (context.checkin !== '' && context.checkout !== '') {
                setMessage(
                    messagesEl,
                    (config.strings && config.strings.invalidRange) || 'Please select valid dates.',
                    'error'
                );
            } else {
                setMessage(messagesEl, '', '');
            }

            if (resultsEl) {
                resultsEl.style.display = 'none';
            }

            if (roomListEl) {
                roomListEl.innerHTML = '';
            }

            return;
        }

        setMessage(messagesEl, '', '');
        setLoading(loadingEl, true);

        var params = new URLSearchParams();
        params.append('action', String(config.availabilityAction));
        params.append('checkin', context.checkin);
        params.append('checkout', context.checkout);
        params.append('guests', String(context.guests));
        params.append('accommodation_type', String(context.accommodationType || getAccommodationTypeValue()));

        var requestToken = ++state.availabilityRequestToken;

        fetch(String(config.ajaxUrl) + '?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('availability-request-failed');
            }

            return response.json();
        }).then(function (payload) {
            if (requestToken !== state.availabilityRequestToken) {
                return;
            }

            if (!payload || payload.success !== true || !payload.data) {
                throw new Error('availability-response-invalid');
            }

            var rooms = Array.isArray(payload.data.rooms) ? payload.data.rooms : [];
            renderRooms(roomListEl, noRoomsEl, resultsEl, rooms, context);
            syncHiddenSelectionFields(context);
        }).catch(function () {
            if (requestToken !== state.availabilityRequestToken) {
                return;
            }

            setMessage(
                messagesEl,
                (config.strings && config.strings.requestFailed) || 'Unable to load availability.',
                'error'
            );
        }).finally(function () {
            if (requestToken !== state.availabilityRequestToken) {
                return;
            }

            setLoading(loadingEl, false);
        });
    }

    function initializeDatePickers(checkinInput, checkoutInput, onFieldChange) {
        if (typeof window.flatpickr !== 'function') {
            return;
        }

        var checkinCalendar = document.getElementById('must-booking-checkin-calendar');
        var checkoutCalendar = document.getElementById('must-booking-checkout-calendar');

        if (checkinCalendar && checkoutCalendar) {
            state.checkoutPicker = window.flatpickr(checkoutCalendar, {
                inline: true,
                disableMobile: true,
                dateFormat: 'Y-m-d',
                allowInput: false,
                locale: {
                    firstDayOfWeek: 1
                },
                defaultDate: isValidDateString(checkoutInput.value) ? checkoutInput.value : null,
                minDate: isValidDateString(checkinInput.value)
                    ? addDays(checkinInput.value, 1)
                    : (config.today || 'today'),
                onChange: function (selectedDates, dateStr) {
                    checkoutInput.value = dateStr || '';
                    state.previewCheckout = '';
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                    onFieldChange('checkout');
                },
                onMonthChange: function () {
                    updateCalendarMeta(state.checkoutPicker, 'must-booking-checkout-month', 'must-booking-checkout-year');
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                },
                onYearChange: function () {
                    updateCalendarMeta(state.checkoutPicker, 'must-booking-checkout-month', 'must-booking-checkout-year');
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                }
            });

            state.checkinPicker = window.flatpickr(checkinCalendar, {
                inline: true,
                disableMobile: true,
                dateFormat: 'Y-m-d',
                allowInput: false,
                locale: {
                    firstDayOfWeek: 1
                },
                defaultDate: isValidDateString(checkinInput.value) ? checkinInput.value : null,
                minDate: config.today || 'today',
                onChange: function (selectedDates, dateStr) {
                    checkinInput.value = dateStr || '';

                    if (!dateStr) {
                        checkoutInput.value = '';
                        state.checkoutPicker.clear();
                        state.checkoutPicker.set('minDate', config.today || 'today');
                    } else {
                        var minCheckout = addDays(dateStr, 1);
                        state.checkoutPicker.set('minDate', minCheckout);

                        if (!isValidDateString(checkoutInput.value) || checkoutInput.value <= dateStr) {
                            checkoutInput.value = '';
                            state.checkoutPicker.clear();
                            state.checkoutPicker.jumpToDate(minCheckout, true);
                        }
                    }

                    state.previewCheckout = '';
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                    onFieldChange('checkin');
                },
                onMonthChange: function () {
                    updateCalendarMeta(state.checkinPicker, 'must-booking-checkin-month', 'must-booking-checkin-year');
                    updateCalendarShiftState();
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                },
                onYearChange: function () {
                    updateCalendarMeta(state.checkinPicker, 'must-booking-checkin-month', 'must-booking-checkin-year');
                    updateCalendarShiftState();
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                }
            });

            if (!isValidDateString(checkoutInput.value)) {
                state.checkoutPicker.changeMonth(1, false);
            }

            if (state.checkinPicker && state.checkinPicker.calendarContainer) {
                state.checkinPicker.calendarContainer.classList.add('must-booking-flatpickr-instance');
            }

            if (state.checkoutPicker && state.checkoutPicker.calendarContainer) {
                state.checkoutPicker.calendarContainer.classList.add('must-booking-flatpickr-instance');
            }

            updateCalendarMeta(state.checkinPicker, 'must-booking-checkin-month', 'must-booking-checkin-year');
            updateCalendarMeta(state.checkoutPicker, 'must-booking-checkout-month', 'must-booking-checkout-year');
            updateCalendarShiftState();
            refreshUnavailableDayClasses();
            updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);

            var previousButton = document.getElementById('must-booking-cal-prev');
            var nextButton = document.getElementById('must-booking-cal-next');
            var checkinMonthSelect = document.getElementById('must-booking-checkin-month');
            var checkinYearSelect = document.getElementById('must-booking-checkin-year');
            var checkoutMonthSelect = document.getElementById('must-booking-checkout-month');
            var checkoutYearSelect = document.getElementById('must-booking-checkout-year');

            if (previousButton) {
                previousButton.addEventListener('click', function () {
                    var floorDate = getMonthFloorDate();
                    var checkinMonthDate = new Date(state.checkinPicker.currentYear, state.checkinPicker.currentMonth, 1);

                    if (checkinMonthDate.getTime() <= floorDate.getTime()) {
                        updateCalendarShiftState();
                        return;
                    }

                    state.checkinPicker.changeMonth(-1);
                    updateCalendarMeta(state.checkinPicker, 'must-booking-checkin-month', 'must-booking-checkin-year');
                    updateCalendarShiftState();
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                });
            }

            if (nextButton) {
                nextButton.addEventListener('click', function () {
                    state.checkoutPicker.changeMonth(1);
                    updateCalendarMeta(state.checkoutPicker, 'must-booking-checkout-month', 'must-booking-checkout-year');
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                });
            }

            if (checkinMonthSelect) {
                checkinMonthSelect.addEventListener('change', function () {
                    var month = parseInt(String(checkinMonthSelect.value || '0'), 10);
                    var year = parseInt(String((checkinYearSelect && checkinYearSelect.value) || state.checkinPicker.currentYear), 10);

                    if (!Number.isFinite(month) || month < 0 || month > 11 || !Number.isFinite(year)) {
                        return;
                    }

                    var floorDate = getMonthFloorDate();
                    var target = new Date(year, month, 1);

                    if (target.getTime() < floorDate.getTime()) {
                        target = floorDate;
                    }

                    state.checkinPicker.jumpToDate(target, true);
                    state.checkoutPicker.jumpToDate(new Date(target.getFullYear(), target.getMonth() + 1, 1), true);
                    updateCalendarMeta(state.checkinPicker, 'must-booking-checkin-month', 'must-booking-checkin-year');
                    updateCalendarMeta(state.checkoutPicker, 'must-booking-checkout-month', 'must-booking-checkout-year');
                    updateCalendarShiftState();
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                });
            }

            if (checkinYearSelect) {
                checkinYearSelect.addEventListener('change', function () {
                    if (checkinMonthSelect) {
                        checkinMonthSelect.dispatchEvent(new Event('change'));
                    }
                });
            }

            if (checkoutMonthSelect) {
                checkoutMonthSelect.addEventListener('change', function () {
                    var month = parseInt(String(checkoutMonthSelect.value || '0'), 10);
                    var year = parseInt(String((checkoutYearSelect && checkoutYearSelect.value) || state.checkoutPicker.currentYear), 10);

                    if (!Number.isFinite(month) || month < 0 || month > 11 || !Number.isFinite(year)) {
                        return;
                    }

                    var minDate = parseDateString(checkinInput.value ? addDays(checkinInput.value, 1) : (config.today || ''));
                    var target = new Date(year, month, 1);

                    if (minDate && target.getTime() < new Date(minDate.getFullYear(), minDate.getMonth(), 1).getTime()) {
                        target = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
                    }

                    state.checkoutPicker.jumpToDate(target, true);
                    updateCalendarMeta(state.checkoutPicker, 'must-booking-checkout-month', 'must-booking-checkout-year');
                    refreshUnavailableDayClasses();
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                });
            }

            if (checkoutYearSelect) {
                checkoutYearSelect.addEventListener('change', function () {
                    if (checkoutMonthSelect) {
                        checkoutMonthSelect.dispatchEvent(new Event('change'));
                    }
                });
            }

            if (state.checkoutPicker && state.checkoutPicker.calendarContainer) {
                state.checkoutPicker.calendarContainer.addEventListener('mouseover', function (event) {
                    var target = event.target && event.target.closest ? event.target.closest('.flatpickr-day') : null;

                    if (!target || !target.dateObj) {
                        return;
                    }

                    if (target.classList.contains('flatpickr-disabled') || target.classList.contains('notAllowed')) {
                        return;
                    }

                    if (!checkinInput.value) {
                        return;
                    }

                    var hovered = formatDate(target.dateObj);

                    if (hovered <= String(checkinInput.value || '')) {
                        state.previewCheckout = '';
                        updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                        return;
                    }

                    state.previewCheckout = hovered;
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                });

                state.checkoutPicker.calendarContainer.addEventListener('mouseleave', function () {
                    state.previewCheckout = '';
                    updateRangeHighlights(checkinInput.value, checkoutInput.value, state.previewCheckout);
                });
            }

            return;
        }

        state.checkoutPicker = window.flatpickr(checkoutInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            minDate: checkinInput.value ? addDays(checkinInput.value, 1) : (config.today || 'today'),
            onChange: function () {
                onFieldChange('checkout');
            }
        });

        state.checkinPicker = window.flatpickr(checkinInput, {
            dateFormat: 'Y-m-d',
            allowInput: false,
            minDate: config.today || 'today',
            onChange: function () {
                onFieldChange('checkin');
            }
        });
    }

    function initBookingPage() {
        var form = document.getElementById('must-booking-search-form');
        var checkinInput = document.getElementById('must-booking-checkin');
        var checkoutInput = document.getElementById('must-booking-checkout');
        var guestsInput = document.getElementById('must-booking-guests');
        var guestsSelect = document.getElementById('must-booking-guests-select');
        var accommodationTypeSelect = document.getElementById('must-booking-accommodation-type');
        var stepBackButton = document.getElementById('must-booking-step-back');
        var stepNextButton = document.getElementById('must-booking-step-next');
        var resultsEl = document.getElementById('must-booking-results');
        var roomListEl = document.getElementById('must-booking-room-list');
        var noRoomsEl = document.getElementById('must-booking-no-rooms-message');
        var loadingEl = document.getElementById('must-booking-loading');
        var messagesEl = document.getElementById('must-booking-live-messages');
        var editDatesButton = document.getElementById('must-booking-results-edit-dates');
        var editSummaryButton = document.getElementById('must-booking-results-edit-summary');

        if (!form || !checkinInput || !checkoutInput || !guestsInput || !roomListEl || !noRoomsEl || !resultsEl) {
            return;
        }

        if (config.initial && typeof config.initial === 'object') {
            if (!checkinInput.value && isValidDateString(config.initial.checkin || '')) {
                checkinInput.value = String(config.initial.checkin);
            }

            if (!checkoutInput.value && isValidDateString(config.initial.checkout || '')) {
                checkoutInput.value = String(config.initial.checkout);
            }

            if (!guestsInput.value) {
                guestsInput.value = String(config.initial.guests || 1);
            }
        }

        if (accommodationTypeSelect) {
            accommodationTypeSelect.value = getAccommodationTypeValue(accommodationTypeSelect);
        }

        if (guestsSelect) {
            guestsInput.value = String(getContext(checkinInput, checkoutInput, guestsInput).guests || 1);
            guestsSelect.value = String(guestsInput.value || '1');
        }

        function refreshCalendarState(source) {
            var context = getContext(checkinInput, checkoutInput, guestsInput);
            syncHiddenSelectionFields(context);
            updateSummary(context);
            updateResultsSummary(context, accommodationTypeSelect);
            updateRangeHighlights(context.checkin, context.checkout, state.previewCheckout);
            setCurrentStep(1, resultsEl);

            if (source === 'checkin' || source === 'guests' || source === 'accommodation_type') {
                fetchDisabledDates(context).finally(function () {
                    var updatedContext = getContext(checkinInput, checkoutInput, guestsInput);
                    syncHiddenSelectionFields(updatedContext);
                    updateSummary(updatedContext);
                    updateResultsSummary(updatedContext, accommodationTypeSelect);
                    updateRangeHighlights(updatedContext.checkin, updatedContext.checkout, state.previewCheckout);
                    if (!isCalendarPageMode() && source === 'accommodation_type' && isValidRange(updatedContext)) {
                        setMessage(messagesEl, '', '');
                        setCurrentStep(2, resultsEl);
                        fetchAvailability(updatedContext, roomListEl, noRoomsEl, resultsEl, loadingEl, messagesEl);
                        return;
                    }

                    setCurrentStep(1, resultsEl);
                });

                return;
            }
        }

        function runAvailabilityCheck() {
            var context = getContext(checkinInput, checkoutInput, guestsInput);

            syncHiddenSelectionFields(context);
            updateSummary(context);
            updateResultsSummary(context, accommodationTypeSelect);
            updateRangeHighlights(context.checkin, context.checkout, state.previewCheckout);

            if (!isValidRange(context)) {
                setMessage(
                    messagesEl,
                    (config.strings && config.strings.invalidRange) || 'Please select valid dates.',
                    'error'
                );
                setCurrentStep(1, resultsEl);
                return;
            }

            setMessage(messagesEl, '', '');

            if (isCalendarPageMode()) {
                redirectToAccommodation(context);
                return;
            }

            setCurrentStep(2, resultsEl);
            fetchAvailability(context, roomListEl, noRoomsEl, resultsEl, loadingEl, messagesEl);
        }

        function scheduleRefresh(source) {
            if (state.refreshTimer !== null) {
                window.clearTimeout(state.refreshTimer);
            }

            state.refreshTimer = window.setTimeout(function () {
                refreshCalendarState(source);
            }, 220);
        }

        initializeDatePickers(checkinInput, checkoutInput, scheduleRefresh);

        var initialContext = getContext(checkinInput, checkoutInput, guestsInput, accommodationTypeSelect);
        var initialStep = isCalendarPageMode() ? 1 : parseInt(String(config.initialStep || '1'), 10);

        syncHiddenSelectionFields(initialContext);
        updateSummary(initialContext);
        updateResultsSummary(initialContext, accommodationTypeSelect);
        updateRangeHighlights(initialContext.checkin, initialContext.checkout, state.previewCheckout);
        setCurrentStep(initialStep > 1 && isValidRange(initialContext) ? 2 : 1, resultsEl);

        if (guestsSelect) {
            guestsSelect.addEventListener('change', function () {
                var guestsValue = parseInt(String(guestsSelect.value || '1'), 10);
                var maxGuests = getMaxGuestsLimit();

                if (!Number.isFinite(guestsValue) || guestsValue < 1) {
                    guestsValue = 1;
                }

                if (guestsValue > maxGuests) {
                    guestsValue = maxGuests;
                }

                guestsInput.value = String(guestsValue);
                scheduleRefresh('guests');
            });
        } else {
            guestsInput.addEventListener('change', function () {
                scheduleRefresh('guests');
            });

            guestsInput.addEventListener('input', function () {
                scheduleRefresh('guests');
            });
        }

        if (accommodationTypeSelect) {
            accommodationTypeSelect.addEventListener('change', function () {
                scheduleRefresh('accommodation_type');
            });
        }

        [editDatesButton, editSummaryButton].forEach(function (button) {
            if (!button) {
                return;
            }

            button.addEventListener('click', function (event) {
                event.preventDefault();
                setMessage(messagesEl, '', '');
                setCurrentStep(1, resultsEl);
            });
        });

        if (stepBackButton) {
            stepBackButton.addEventListener('click', function (event) {
                event.preventDefault();

                if (state.currentStep <= 1) {
                    window.location.href = getHomeUrl();
                    return;
                }

                setMessage(messagesEl, '', '');
                setCurrentStep(state.currentStep - 1, resultsEl);
            });
        }

        if (stepNextButton) {
            stepNextButton.addEventListener('click', function (event) {
                event.preventDefault();

                if (state.currentStep > 1) {
                    return;
                }

                if (guestsSelect) {
                    guestsInput.value = String(guestsSelect.value || guestsInput.value || '1');
                }

                var context = getContext(checkinInput, checkoutInput, guestsInput);

                if (!isValidRange(context)) {
                    setMessage(
                        messagesEl,
                        (config.strings && config.strings.invalidRange) || 'Please select valid dates.',
                        'error'
                    );
                    setCurrentStep(1, resultsEl);
                    return;
                }

                runAvailabilityCheck();
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (guestsSelect) {
                guestsInput.value = String(guestsSelect.value || guestsInput.value || '1');
            }

            runAvailabilityCheck();
        });

        fetchDisabledDates(initialContext).finally(function () {
            var contextAfterLoad = getContext(checkinInput, checkoutInput, guestsInput, accommodationTypeSelect);

            syncHiddenSelectionFields(contextAfterLoad);
            updateSummary(contextAfterLoad);
            updateResultsSummary(contextAfterLoad, accommodationTypeSelect);
            updateRangeHighlights(contextAfterLoad.checkin, contextAfterLoad.checkout, state.previewCheckout);

            if (!isCalendarPageMode() && initialStep > 1 && isValidRange(contextAfterLoad)) {
                setCurrentStep(2, resultsEl);

                if (roomListEl.children.length === 0 || roomListEl.querySelector('.must-hotel-booking-room-card') === null) {
                    fetchAvailability(contextAfterLoad, roomListEl, noRoomsEl, resultsEl, loadingEl, messagesEl);
                }

                return;
            }

            setCurrentStep(1, resultsEl);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBookingPage);
    } else {
        initBookingPage();
    }
})();
