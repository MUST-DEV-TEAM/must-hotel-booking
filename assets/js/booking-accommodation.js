(function () {
    'use strict';

    var cardSelector = '.must-booking-accommodation-room-card';
    var lightboxTriggerSelector = '.must-booking-room-image-trigger';
    var modalTriggerSelector = '.must-booking-room-modal-trigger';

    var lightbox = null;
    var lightboxImageNode = null;
    var lightboxCaptionNode = null;
    var lightboxCloseNode = null;
    var lightboxPrevNode = null;
    var lightboxNextNode = null;
    var lightboxImages = [];
    var lightboxIndex = 0;
    var lightboxTitle = '';
    var lightboxOpen = false;
    var lightboxLastFocused = null;

    var roomModal = null;
    var roomModalBodyNode = null;
    var roomModalCloseNode = null;
    var roomModalOpen = false;
    var roomModalLastFocused = null;

    function parseImages(value) {
        if (!value) {
            return [];
        }

        try {
            var parsed = JSON.parse(value);

            if (!Array.isArray(parsed)) {
                return [];
            }

            return parsed.filter(function (item) {
                return typeof item === 'string' && item !== '';
            });
        } catch (error) {
            return [];
        }
    }

    function updateLightboxCaption() {
        if (!lightboxCaptionNode) {
            return;
        }

        var position = String(lightboxIndex + 1) + '/' + String(lightboxImages.length);

        if (lightboxTitle) {
            lightboxCaptionNode.textContent = lightboxTitle + ' (' + position + ')';
            return;
        }

        lightboxCaptionNode.textContent = position;
    }

    function updateLightboxNav() {
        var hasMultiple = lightboxImages.length > 1;

        if (lightboxPrevNode) {
            lightboxPrevNode.hidden = !hasMultiple;
            lightboxPrevNode.disabled = !hasMultiple;
        }

        if (lightboxNextNode) {
            lightboxNextNode.hidden = !hasMultiple;
            lightboxNextNode.disabled = !hasMultiple;
        }
    }

    function showLightboxImage(index) {
        if (!lightboxImageNode || lightboxImages.length === 0) {
            return;
        }

        if (index < 0) {
            index = lightboxImages.length - 1;
        }

        if (index > lightboxImages.length - 1) {
            index = 0;
        }

        lightboxIndex = index;
        lightboxImageNode.src = lightboxImages[lightboxIndex];
        lightboxImageNode.alt = lightboxTitle || 'Room image';
        updateLightboxCaption();
        updateLightboxNav();
    }

    function closeLightbox() {
        if (!lightbox || !lightboxOpen) {
            return;
        }

        lightboxOpen = false;
        lightbox.hidden = true;
        lightbox.classList.remove('is-open');
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('must-hotel-booking-lightbox-open');

        if (lightboxImageNode) {
            lightboxImageNode.removeAttribute('src');
        }

        if (lightboxLastFocused && typeof lightboxLastFocused.focus === 'function') {
            lightboxLastFocused.focus();
        }
    }

    function openLightbox(images, startIndex, title) {
        if (!Array.isArray(images) || images.length === 0) {
            return;
        }

        buildLightbox();
        lightboxImages = images;
        lightboxTitle = title || '';
        lightboxIndex = Number.isFinite(startIndex) ? startIndex : 0;
        lightboxLastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        lightboxOpen = true;
        lightbox.hidden = false;
        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.classList.add('must-hotel-booking-lightbox-open');
        showLightboxImage(lightboxIndex);

        if (lightboxCloseNode) {
            lightboxCloseNode.focus();
        }
    }

    function buildLightbox() {
        if (lightbox) {
            return;
        }

        lightbox = document.createElement('div');
        lightbox.className = 'must-hotel-booking-lightbox';
        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.innerHTML =
            '<div class="must-hotel-booking-lightbox-backdrop" data-lightbox-close="1"></div>' +
            '<div class="must-hotel-booking-lightbox-dialog" role="document">' +
            '<button type="button" class="must-hotel-booking-lightbox-close" aria-label="Close image preview">&times;</button>' +
            '<button type="button" class="must-hotel-booking-lightbox-nav must-hotel-booking-lightbox-prev" aria-label="Previous image">&#10094;</button>' +
            '<figure class="must-hotel-booking-lightbox-figure">' +
            '<img class="must-hotel-booking-lightbox-image" src="" alt="" />' +
            '<figcaption class="must-hotel-booking-lightbox-caption"></figcaption>' +
            '</figure>' +
            '<button type="button" class="must-hotel-booking-lightbox-nav must-hotel-booking-lightbox-next" aria-label="Next image">&#10095;</button>' +
            '</div>';

        document.body.appendChild(lightbox);

        lightboxImageNode = lightbox.querySelector('.must-hotel-booking-lightbox-image');
        lightboxCaptionNode = lightbox.querySelector('.must-hotel-booking-lightbox-caption');
        lightboxCloseNode = lightbox.querySelector('.must-hotel-booking-lightbox-close');
        lightboxPrevNode = lightbox.querySelector('.must-hotel-booking-lightbox-prev');
        lightboxNextNode = lightbox.querySelector('.must-hotel-booking-lightbox-next');

        lightbox.addEventListener('click', function (event) {
            var target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.dataset.lightboxClose === '1') {
                closeLightbox();
            }
        });

        if (lightboxCloseNode) {
            lightboxCloseNode.addEventListener('click', closeLightbox);
        }

        if (lightboxPrevNode) {
            lightboxPrevNode.addEventListener('click', function () {
                showLightboxImage(lightboxIndex - 1);
            });
        }

        if (lightboxNextNode) {
            lightboxNextNode.addEventListener('click', function () {
                showLightboxImage(lightboxIndex + 1);
            });
        }
    }

    function buildRoomModal() {
        if (roomModal) {
            return;
        }

        roomModal = document.createElement('div');
        roomModal.className = 'must-booking-room-modal';
        roomModal.hidden = true;
        roomModal.innerHTML =
            '<div class="must-booking-room-modal-backdrop" data-room-modal-close="1"></div>' +
            '<div class="must-booking-room-modal-dialog" role="dialog" aria-modal="true">' +
            '<button type="button" class="must-booking-room-modal-close" aria-label="Close additional details"></button>' +
            '<div class="must-booking-room-modal-body"></div>' +
            '</div>';

        document.body.appendChild(roomModal);

        roomModalBodyNode = roomModal.querySelector('.must-booking-room-modal-body');
        roomModalCloseNode = roomModal.querySelector('.must-booking-room-modal-close');

        roomModal.addEventListener('click', function (event) {
            var target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.dataset.roomModalClose === '1') {
                closeRoomModal();
            }
        });

        if (roomModalCloseNode) {
            roomModalCloseNode.addEventListener('click', closeRoomModal);
        }
    }

    function closeRoomModal() {
        if (!roomModal || !roomModalOpen) {
            return;
        }

        roomModalOpen = false;
        roomModal.hidden = true;
        roomModal.classList.remove('is-open');
        document.body.classList.remove('must-booking-room-modal-open');

        if (roomModalBodyNode) {
            roomModalBodyNode.innerHTML = '';
        }

        if (roomModalLastFocused && typeof roomModalLastFocused.focus === 'function') {
            roomModalLastFocused.focus();
        }
    }

    function openRoomModalFromTrigger(triggerNode) {
        if (!(triggerNode instanceof HTMLElement)) {
            return;
        }

        var templateId = String(triggerNode.getAttribute('data-room-modal-id') || '');

        if (templateId === '') {
            return;
        }

        var templateNode = document.getElementById(templateId);

        if (!(templateNode instanceof HTMLTemplateElement)) {
            return;
        }

        buildRoomModal();

        if (!roomModalBodyNode) {
            return;
        }

        roomModalBodyNode.innerHTML = '';
        roomModalBodyNode.appendChild(templateNode.content.cloneNode(true));
        roomModalLastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        roomModalOpen = true;
        roomModal.hidden = false;
        roomModal.classList.add('is-open');
        document.body.classList.add('must-booking-room-modal-open');

        if (roomModalCloseNode) {
            roomModalCloseNode.focus();
        }
    }

    function onDocumentClick(event) {
        var target = event.target;

        if (!(target instanceof HTMLElement)) {
            return;
        }

        var lightboxTriggerNode = target.closest(lightboxTriggerSelector);

        if (lightboxTriggerNode) {
            var cardNode = lightboxTriggerNode.closest(cardSelector);

            if (!cardNode) {
                return;
            }

            var images = parseImages(cardNode.getAttribute('data-lightbox-images'));

            if (images.length === 0) {
                return;
            }

            var index = parseInt(String(lightboxTriggerNode.getAttribute('data-lightbox-index') || '0'), 10);

            if (!Number.isFinite(index)) {
                index = 0;
            }

            event.preventDefault();
            openLightbox(images, index, String(cardNode.getAttribute('data-lightbox-title') || ''));
            return;
        }

        var modalTriggerNode = target.closest(modalTriggerSelector);

        if (!modalTriggerNode) {
            return;
        }

        event.preventDefault();
        openRoomModalFromTrigger(modalTriggerNode);
    }

    function onDocumentKeyDown(event) {
        if (roomModalOpen && event.key === 'Escape') {
            event.preventDefault();
            closeRoomModal();
            return;
        }

        if (!lightboxOpen) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeLightbox();
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            showLightboxImage(lightboxIndex - 1);
            return;
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            showLightboxImage(lightboxIndex + 1);
        }
    }

    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onDocumentKeyDown);
})();
