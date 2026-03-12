(function ($) {
    'use strict';

    function getThumbUrl(attachment) {
        if (attachment && attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
            return attachment.sizes.thumbnail.url;
        }

        if (attachment && attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) {
            return attachment.sizes.medium.url;
        }

        if (attachment && attachment.url) {
            return attachment.url;
        }

        return '';
    }

    function buildGalleryPreviewHtml(attachments) {
        var html = '';

        attachments.forEach(function (attachment) {
            var thumbUrl = getThumbUrl(attachment);

            if (!thumbUrl) {
                return;
            }

            html += '<img class="must-room-gallery-thumb" src="' + thumbUrl + '" alt="" />';
        });

        return html;
    }

    function buildMainPreviewHtml(attachment) {
        var thumbUrl = getThumbUrl(attachment);

        if (!thumbUrl) {
            return '';
        }

        return '<img class="must-room-main-image-thumb" src="' + thumbUrl + '" alt="" />';
    }

    $(document).on('click', '.must-room-upload-main-image', function (event) {
        event.preventDefault();

        var $fieldWrap = $(this).closest('.must-room-main-image-field');
        var $input = $fieldWrap.find('.must-room-main-image-id');
        var $preview = $fieldWrap.find('.must-room-main-image-preview');

        var frame = wp.media({
            title: (window.mustHotelBookingRoomsMedia && window.mustHotelBookingRoomsMedia.mainImageFrameTitle) || 'Select Main Image',
            button: {
                text: (window.mustHotelBookingRoomsMedia && window.mustHotelBookingRoomsMedia.mainImageButtonText) || 'Use Main Image'
            },
            multiple: false
        });

        frame.on('select', function () {
            var selection = frame.state().get('selection').first();

            if (!selection) {
                return;
            }

            var attachment = selection.toJSON();

            if (!attachment || !attachment.id) {
                return;
            }

            $input.val(parseInt(attachment.id, 10));
            $preview.html(buildMainPreviewHtml(attachment));
        });

        frame.open();
    });

    $(document).on('click', '.must-room-clear-main-image', function (event) {
        event.preventDefault();

        var $fieldWrap = $(this).closest('.must-room-main-image-field');

        $fieldWrap.find('.must-room-main-image-id').val('');
        $fieldWrap.find('.must-room-main-image-preview').empty();
    });

    $(document).on('click', '.must-room-upload-images', function (event) {
        event.preventDefault();

        var $fieldWrap = $(this).closest('.must-room-gallery-field');
        var $input = $fieldWrap.find('.must-room-gallery-ids');
        var $preview = $fieldWrap.find('.must-room-gallery-preview');

        var frame = wp.media({
            title: (window.mustHotelBookingRoomsMedia && window.mustHotelBookingRoomsMedia.galleryFrameTitle) || 'Select Gallery Images',
            button: {
                text: (window.mustHotelBookingRoomsMedia && window.mustHotelBookingRoomsMedia.galleryButtonText) || 'Use Images'
            },
            multiple: true
        });

        frame.on('select', function () {
            var selection = frame.state().get('selection').toJSON();
            var ids = [];

            selection.forEach(function (attachment) {
                if (attachment.id) {
                    ids.push(parseInt(attachment.id, 10));
                }
            });

            $input.val(ids.join(','));
            $preview.html(buildGalleryPreviewHtml(selection));
        });

        frame.open();
    });

    $(document).on('click', '.must-room-clear-images', function (event) {
        event.preventDefault();

        var $fieldWrap = $(this).closest('.must-room-gallery-field');

        $fieldWrap.find('.must-room-gallery-ids').val('');
        $fieldWrap.find('.must-room-gallery-preview').empty();
    });
})(jQuery);
