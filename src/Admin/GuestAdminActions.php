<?php

namespace MustHotelBooking\Admin;

final class GuestAdminActions
{
    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function sanitizeGuestForm(array $source): array
    {
        return [
            'first_name' => isset($source['first_name']) ? \sanitize_text_field((string) \wp_unslash($source['first_name'])) : '',
            'last_name' => isset($source['last_name']) ? \sanitize_text_field((string) \wp_unslash($source['last_name'])) : '',
            'email' => isset($source['email']) ? \sanitize_email((string) \wp_unslash($source['email'])) : '',
            'phone' => isset($source['phone']) ? \sanitize_text_field((string) \wp_unslash($source['phone'])) : '',
            'country' => isset($source['country']) ? \sanitize_text_field((string) \wp_unslash($source['country'])) : '',
            'admin_notes' => isset($source['admin_notes']) ? \sanitize_textarea_field((string) \wp_unslash($source['admin_notes'])) : '',
            'vip_flag' => !empty($source['vip_flag']) ? 1 : 0,
            'problem_flag' => !empty($source['problem_flag']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @return array<int, string>
     */
    public static function validateGuestForm(array $form): array
    {
        $errors = [];

        if (($form['email'] ?? '') !== '' && !\is_email((string) $form['email'])) {
            $errors[] = \__('Please enter a valid guest email address.', 'must-hotel-booking');
        }

        if (($form['first_name'] ?? '') === '' && ($form['last_name'] ?? '') === '' && ($form['email'] ?? '') === '') {
            $errors[] = \__('At least a guest name or email is required.', 'must-hotel-booking');
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function handleSaveRequest(GuestAdminQuery $query): array
    {
        $state = [
            'errors' => [],
            'form' => null,
            'selected_guest_id' => $query->getGuestId(),
        ];

        $action = isset($_POST['must_guest_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_guest_action'])) : '';

        if ($action !== 'save_guest') {
            return $state;
        }

        if (!isset($_POST['must_guest_nonce']) || !\wp_verify_nonce((string) \wp_unslash($_POST['must_guest_nonce']), 'must_guest_save')) {
            return [
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'form' => null,
                'selected_guest_id' => $query->getGuestId(),
            ];
        }

        $guestId = isset($_POST['guest_id']) ? \absint(\wp_unslash($_POST['guest_id'])) : 0;
        $form = self::sanitizeGuestForm($_POST);
        $state['form'] = $form;
        $state['selected_guest_id'] = $guestId;

        if ($guestId <= 0) {
            $state['errors'][] = \__('Guest record could not be resolved.', 'must-hotel-booking');

            return $state;
        }

        $state['errors'] = self::validateGuestForm($form);

        if (!empty($state['errors'])) {
            return $state;
        }

        $updated = \MustHotelBooking\Engine\get_guest_repository()->updateAdminGuestRecord(
            $guestId,
            [
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'email' => $form['email'],
                'phone' => $form['phone'],
                'country' => $form['country'],
                'admin_notes' => $form['admin_notes'],
                'vip_flag' => $form['vip_flag'],
                'problem_flag' => $form['problem_flag'],
            ]
        );

        if (!$updated) {
            $state['errors'][] = \__('Unable to save the guest profile.', 'must-hotel-booking');

            return $state;
        }

        \wp_safe_redirect(
            get_admin_guests_page_url(
                $query->buildUrlArgs(
                    [
                        'guest_id' => $guestId,
                        'notice' => 'guest_saved',
                    ]
                )
            )
        );
        exit;
    }

    public function handleGetAction(GuestAdminQuery $query): void
    {
        unset($query);
    }
}
