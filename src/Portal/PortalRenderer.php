<?php

namespace MustHotelBooking\Portal;

final class PortalRenderer
{
    /**
     * @param array<string, mixed> $state
     */
    public static function renderPortalPage(array $state): void
    {
        $branding = isset($state['branding']) && \is_array($state['branding']) ? $state['branding'] : [];
        $module = isset($state['current_module']) && \is_array($state['current_module']) ? $state['current_module'] : [];
        $moduleKey = (string) ($state['current_module_key'] ?? 'dashboard');

        self::renderDocumentStart(
            \sprintf(
                /* translators: %s is the current portal section. */
                \__('%s | Staff Portal', 'must-hotel-booking'),
                (string) ($module['label'] ?? \__('Staff Portal', 'must-hotel-booking'))
            ),
            $branding,
            'must-portal-page'
        );

        echo '<div class="must-portal-shell">';
        echo '<aside class="must-portal-sidebar">';
        echo '<div class="must-portal-brand">';

        if (!empty($branding['logo_url'])) {
            echo '<img src="' . \esc_url((string) $branding['logo_url']) . '" alt="' . \esc_attr((string) ($branding['hotel_name'] ?? '')) . '" />';
        }

        echo '<div><strong>' . \esc_html((string) ($branding['hotel_name'] ?? \__('Hotel', 'must-hotel-booking'))) . '</strong><span>' . \esc_html__('Staff Portal', 'must-hotel-booking') . '</span></div></div>';
        echo '<nav class="must-portal-nav">';

        foreach ((array) ($state['navigation'] ?? []) as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $classes = ['must-portal-nav-item'];

            if (!empty($item['current'])) {
                $classes[] = 'is-current';
            }

            echo '<a class="' . \esc_attr(\implode(' ', $classes)) . '" href="' . \esc_url((string) ($item['url'] ?? '#')) . '"><span class="dashicons ' . \esc_attr((string) ($item['icon'] ?? 'dashicons-arrow-right-alt2')) . '"></span><span>' . \esc_html((string) ($item['label'] ?? '')) . '</span></a>';
        }

        echo '</nav><div class="must-portal-sidebar-footer"><a class="must-portal-secondary-link" href="' . \esc_url((string) ($state['logout_url'] ?? '#')) . '">' . \esc_html__('Sign out', 'must-hotel-booking') . '</a></div></aside>';
        echo '<div class="must-portal-main">';
        echo '<header class="must-portal-header"><div><span class="must-portal-eyebrow">' . \esc_html__('Operations Workspace', 'must-hotel-booking') . '</span><h1>' . \esc_html((string) ($module['label'] ?? \__('Staff Portal', 'must-hotel-booking'))) . '</h1></div><div class="must-portal-user"><strong>' . \esc_html((string) (($state['user']['display_name'] ?? '') !== '' ? $state['user']['display_name'] : \__('Staff user', 'must-hotel-booking'))) . '</strong><span>' . \esc_html((string) ($state['user']['role_label'] ?? '')) . '</span></div></header>';

        self::renderFlashMessages((array) ($state['notices'] ?? []), (array) ($state['errors'] ?? []));

        echo '<main class="must-portal-content">';
        self::renderModulePartial($moduleKey, $state);
        echo '</main></div></div>';

        self::renderDocumentEnd();
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function renderLoginPage(array $state): void
    {
        $branding = isset($state['branding']) && \is_array($state['branding']) ? $state['branding'] : [];
        $hotelName = (string) ($branding['hotel_name'] ?? \__('Hotel', 'must-hotel-booking'));
        $welcomeTitle = (string) ($branding['welcome_title'] ?? \__('Welcome back', 'must-hotel-booking'));
        $welcomeText = !empty($branding['welcome_text'])
            ? (string) $branding['welcome_text']
            : \__('Your operations workspace for reservations, arrivals, and guest management.', 'must-hotel-booking');
        $todayLabel = \date_i18n('l, F j', \current_time('timestamp'));
        $hasErrors = !empty($state['errors']);

        self::renderDocumentStart(\__('Staff Login', 'must-hotel-booking'), $branding, 'must-portal-login-page');

        echo '<div class="must-portal-login-shell">';
        echo '<div class="must-portal-login-stage">';

        // Left showcase panel
        echo '<section class="must-portal-login-showcase" aria-hidden="true">';
        echo '<div class="must-portal-login-showcase-inner">';
        echo '<div class="must-portal-login-brand">';

        if (!empty($branding['logo_url'])) {
            echo '<img src="' . \esc_url((string) $branding['logo_url']) . '" alt="' . \esc_attr($hotelName) . '" />';
        } else {
            echo '<span class="must-portal-login-brand-icon dashicons dashicons-building" aria-hidden="true"></span>';
        }

        echo '<div><strong>' . \esc_html($hotelName) . '</strong><span>' . \esc_html__('Staff Portal', 'must-hotel-booking') . '</span></div></div>';

        echo '<div class="must-portal-login-hero">';
        echo '<span class="must-portal-eyebrow">' . \esc_html__('Staff Portal', 'must-hotel-booking') . '</span>';
        echo '<div class="must-portal-login-ornament"><span class="must-portal-login-ornament-diamond"></span></div>';
        echo '<h1>' . \esc_html($welcomeTitle) . '</h1>';
        echo '<p class="must-portal-login-copy">' . \esc_html($welcomeText) . '</p>';
        echo '</div>';

        echo '<div class="must-portal-login-meta-grid">';
        echo '<div class="must-portal-login-meta-item"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><div><small>' . \esc_html__('Today', 'must-hotel-booking') . '</small><strong>' . \esc_html($todayLabel) . '</strong></div></div>';
        echo '<div class="must-portal-login-meta-item"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><div><small>' . \esc_html__('Access', 'must-hotel-booking') . '</small><strong>' . \esc_html__('Approved staff only', 'must-hotel-booking') . '</strong></div></div>';
        echo '</div>';

        echo '<p class="must-portal-login-access-note">' . \esc_html__('Access is limited to approved staff accounts. Contact an administrator if you need help with your credentials.', 'must-hotel-booking') . '</p>';

        echo '</div>';
        echo '</section>';

        // Right form panel
        echo '<section class="must-portal-login-panel">';
        echo '<div class="must-portal-login-panel-head">';

        if (!empty($branding['logo_url'])) {
            echo '<img class="must-portal-login-panel-logo" src="' . \esc_url((string) $branding['logo_url']) . '" alt="' . \esc_attr($hotelName) . '" />';
        }

        echo '<h2>' . \esc_html__('Sign in', 'must-hotel-booking') . '</h2>';
        echo '<p>' . \esc_html__('Enter your staff credentials to continue.', 'must-hotel-booking') . '</p>';
        echo '</div>';

        self::renderFlashMessages((array) ($state['notices'] ?? []), (array) ($state['errors'] ?? []));

        echo '<form method="post" action="' . \esc_url((string) ($state['action_url'] ?? PortalRouter::getLoginUrl())) . '" class="must-portal-login-form" novalidate>';
        \wp_nonce_field('must_portal_login', 'must_portal_login_nonce');
        echo '<input type="hidden" name="must_portal_action" value="portal_login" />';

        // Username / email field
        $identifierVal = \esc_attr((string) ($state['values']['identifier'] ?? ''));
        echo '<div class="must-portal-login-field">';
        echo '<label for="must-portal-identifier">' . \esc_html__('Username or email address', 'must-hotel-booking') . '</label>';
        echo '<div class="must-portal-login-input' . ($hasErrors ? ' is-error' : '') . '">';
        echo '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>';
        echo '<input type="text" id="must-portal-identifier" name="portal_identifier" value="' . $identifierVal . '" autocomplete="username" spellcheck="false" autocapitalize="none" placeholder="' . \esc_attr__('name@example.com', 'must-hotel-booking') . '" autofocus required />';
        echo '</div></div>';

        // Password field with show/hide toggle
        echo '<div class="must-portal-login-field">';
        echo '<label for="must-portal-password">' . \esc_html__('Password', 'must-hotel-booking') . '</label>';
        echo '<div class="must-portal-login-input must-portal-login-input--password' . ($hasErrors ? ' is-error' : '') . '">';
        echo '<span class="dashicons dashicons-lock" aria-hidden="true"></span>';
        echo '<input type="password" id="must-portal-password" name="portal_password" autocomplete="current-password" placeholder="' . \esc_attr__('••••••••', 'must-hotel-booking') . '" required />';
        echo '<button type="button" id="must-portal-pw-toggle" class="must-portal-login-pw-toggle" aria-label="' . \esc_attr__('Show password', 'must-hotel-booking') . '" aria-pressed="false" tabindex="0">';
        echo '<span class="must-portal-login-pw-toggle-show dashicons dashicons-visibility" aria-hidden="true"></span>';
        echo '<span class="must-portal-login-pw-toggle-hide dashicons dashicons-hidden" aria-hidden="true" style="display:none"></span>';
        echo '</button>';
        echo '</div></div>';

        // Remember me toggle
        echo '<div class="must-portal-login-row">';
        echo '<label class="must-portal-login-toggle">';
        echo '<input type="checkbox" name="portal_remember" value="1"' . \checked(!empty($state['values']['remember']), true, false) . ' />';
        echo '<span class="must-portal-login-toggle-ui" aria-hidden="true"></span>';
        echo '<span class="must-portal-login-toggle-copy"><strong>' . \esc_html__('Stay signed in', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Keep me signed in on this device', 'must-hotel-booking') . '</small></span>';
        echo '</label>';
        echo '</div>';

        // Submit
        echo '<button type="submit" id="must-portal-submit" class="must-portal-login-submit">';
        echo '<span>' . \esc_html__('Sign in to portal', 'must-hotel-booking') . '</span>';
        echo '<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>';
        echo '</button>';

        echo '</form>';

        echo '<p class="must-portal-login-footer-note">' . \esc_html__('Having trouble? Contact your administrator to reset your password or verify your portal access.', 'must-hotel-booking') . '</p>';

        echo '</section>';
        echo '</div>';
        echo '</div>';

        // Inline JS for password toggle
        echo '<script>';
        echo '(function(){';
        echo 'var btn=document.getElementById("must-portal-pw-toggle")||document.querySelector(".must-portal-login-pw-toggle");';
        echo 'if(!btn)return;';
        echo 'btn.addEventListener("click",function(){';
        echo 'var inp=document.getElementById("must-portal-password");';
        echo 'var show=btn.querySelector(".must-portal-login-pw-toggle-show");';
        echo 'var hide=btn.querySelector(".must-portal-login-pw-toggle-hide");';
        echo 'if(!inp)return;';
        echo 'var isHidden=inp.type==="password";';
        echo 'inp.type=isHidden?"text":"password";';
        echo 'btn.setAttribute("aria-pressed",isHidden?"true":"false");';
        echo 'btn.setAttribute("aria-label",isHidden?' . \json_encode(\__('Hide password', 'must-hotel-booking')) . ':' . \json_encode(\__('Show password', 'must-hotel-booking')) . ');';
        echo 'if(show)show.style.display=isHidden?"none":"";';
        echo 'if(hide)hide.style.display=isHidden?"":"none";';
        echo '});';
        echo '})();';
        echo '</script>';

        self::renderDocumentEnd();
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function renderModulePartial(string $moduleKey, array $state): void
    {
        $moduleKey = \str_replace('_', '-', \sanitize_key($moduleKey));
        $partial = MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/' . $moduleKey . '.php';

        if (!\is_file($partial)) {
            self::renderEmptyState(\__('This portal module is not available yet.', 'must-hotel-booking'));

            return;
        }

        $moduleData = isset($state['module_data']) && \is_array($state['module_data']) ? $state['module_data'] : [];
        include $partial;
    }

    /**
     * @param array<string, string> $branding
     */
    private static function renderDocumentStart(string $title, array $branding, string $bodyClass): void
    {
        $styles = [
            '--must-portal-primary:' . \esc_attr((string) ($branding['primary_color'] ?? '#0f766e')),
            '--must-portal-secondary:' . \esc_attr((string) ($branding['secondary_color'] ?? '#155e75')),
            '--must-portal-accent:' . \esc_attr((string) ($branding['accent_color'] ?? '#f59e0b')),
            '--must-portal-text:' . \esc_attr((string) ($branding['text_color'] ?? '#16212b')),
            '--must-portal-radius:' . \esc_attr((string) ($branding['border_radius'] ?? '18')) . 'px',
            '--must-portal-font:' . \esc_attr((string) ($branding['font_family'] ?? 'Instrument Sans')) . ', system-ui, sans-serif',
        ];

        echo '<!DOCTYPE html><html ' . \get_language_attributes() . '><head>';
        echo '<meta charset="' . \esc_attr(\get_bloginfo('charset')) . '" />';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
        echo '<title>' . \esc_html($title) . '</title>';
        echo '<style>:root{' . \implode(';', $styles) . ';}</style>';
        \wp_head();
        echo '</head><body class="' . \esc_attr($bodyClass) . '">';
        \wp_body_open();
    }

    private static function renderDocumentEnd(): void
    {
        \wp_footer();
        echo '</body></html>';
    }

    /**
     * @param array<int, string> $notices
     * @param array<int, string> $errors
     */
    public static function renderFlashMessages(array $notices, array $errors): void
    {
        if (!empty($notices)) {
            echo '<div class="must-portal-flash-group">';

            foreach ($notices as $notice) {
                echo '<div class="must-portal-flash is-success">' . \esc_html((string) $notice) . '</div>';
            }

            echo '</div>';
        }

        if (!empty($errors)) {
            echo '<div class="must-portal-flash-group">';

            foreach ($errors as $error) {
                echo '<div class="must-portal-flash is-error">' . \esc_html((string) $error) . '</div>';
            }

            echo '</div>';
        }
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     */
    public static function renderSummaryCards(array $cards): void
    {
        if (empty($cards)) {
            return;
        }

        echo '<section class="must-portal-summary-grid">';

        foreach ($cards as $card) {
            if (!\is_array($card)) {
                continue;
            }

            $linkUrl = isset($card['link_url']) ? (string) $card['link_url'] : '';
            $tag = $linkUrl !== '' ? 'a' : 'article';
            $classes = ['must-portal-summary-card'];

            if ($tag === 'a') {
                $classes[] = 'is-link';
                echo '<a class="' . \esc_attr(\implode(' ', $classes)) . '" href="' . \esc_url($linkUrl) . '">';
            } else {
                echo '<article class="' . \esc_attr(\implode(' ', $classes)) . '">';
            }

            echo '<span>' . \esc_html((string) ($card['label'] ?? '')) . '</span><strong>' . \esc_html((string) ($card['value'] ?? '')) . '</strong>';

            if (!empty($card['meta'])) {
                echo '<small>' . \esc_html((string) $card['meta']) . '</small>';
            } elseif (!empty($card['descriptor'])) {
                echo '<small>' . \esc_html((string) $card['descriptor']) . '</small>';
            }

            echo $tag === 'a' ? '</a>' : '</article>';
        }

        echo '</section>';
    }

    public static function renderEmptyState(string $message): void
    {
        echo '<div class="must-portal-empty-state">' . \esc_html($message) . '</div>';
    }

    public static function renderDefinitionRow(string $label, string $value): void
    {
        echo '<div class="must-portal-definition-row"><span>' . \esc_html($label) . '</span><strong>' . \esc_html($value) . '</strong></div>';
    }

    public static function renderBadge(string $status, string $label = ''): void
    {
        $status = \sanitize_key($status);
        $label = $label !== '' ? $label : \ucwords(\str_replace('_', ' ', $status));
        $tone = 'info';

        if (\in_array($status, ['ok', 'healthy', 'active', 'confirmed', 'paid', 'success', 'completed', 'available', 'clean', 'inspected'], true)) {
            $tone = 'ok';
        } elseif (\in_array($status, ['warning', 'pending', 'pending_payment', 'partially_paid', 'unpaid', 'pay_at_hotel', 'maintenance', 'dirty', 'hold'], true)) {
            $tone = 'warning';
        } elseif (\in_array($status, ['error', 'failed', 'payment_failed', 'cancelled', 'invalid', 'missing', 'blocked', 'out_of_service', 'expired', 'out_of_order'], true)) {
            $tone = 'error';
        } elseif (\in_array($status, ['disabled', 'inactive'], true)) {
            $tone = 'disabled';
        }

        echo '<span class="must-portal-badge is-' . \esc_attr($tone) . '">' . \esc_html($label) . '</span>';
    }

    /**
     * @param array<string, mixed> $pagination
     */
    public static function renderPagination(array $pagination, string $moduleKey): void
    {
        $currentPage = isset($pagination['current_page']) ? (int) $pagination['current_page'] : 1;
        $totalPages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;

        if ($totalPages <= 1) {
            return;
        }

        $currentArgs = [];

        foreach ($_GET as $key => $value) {
            if (!\is_string($key) || $key === 'paged' || $key === 'portal_notice') {
                continue;
            }

            if (\is_scalar($value)) {
                $currentArgs[\sanitize_key($key)] = \sanitize_text_field((string) \wp_unslash($value));
            }
        }

        echo '<div class="must-portal-pagination">';

        if ($currentPage > 1) {
            echo '<a href="' . \esc_url(PortalRouter::getModuleUrl($moduleKey, \array_merge($currentArgs, ['paged' => $currentPage - 1]))) . '">' . \esc_html__('Previous', 'must-hotel-booking') . '</a>';
        }

        echo '<span>' . \esc_html(\sprintf(\__('Page %1$d of %2$d', 'must-hotel-booking'), $currentPage, $totalPages)) . '</span>';

        if ($currentPage < $totalPages) {
            echo '<a href="' . \esc_url(PortalRouter::getModuleUrl($moduleKey, \array_merge($currentArgs, ['paged' => $currentPage + 1]))) . '">' . \esc_html__('Next', 'must-hotel-booking') . '</a>';
        }

        echo '</div>';
    }
}
