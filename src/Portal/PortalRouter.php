<?php

namespace MustHotelBooking\Portal;

use MustHotelBooking\Core\ManagedPages;

final class PortalRouter
{
    private const REWRITE_SIGNATURE_OPTION = 'must_hotel_booking_portal_rewrite_signature';
    private const REWRITE_SCHEMA_VERSION = 2;

    public static function registerHooks(): void
    {
        \add_action('init', [self::class, 'registerRewriteRules']);
        \add_action('init', [self::class, 'maybeRefreshRewriteRules'], 20);
        \add_filter('query_vars', [self::class, 'registerQueryVars']);
        \add_filter('request', [self::class, 'injectPortalRequest'], 0);
    }

    /**
     * @param array<int, string> $queryVars
     * @return array<int, string>
     */
    public static function registerQueryVars(array $queryVars): array
    {
        $queryVars[] = 'must_hotel_booking_portal_route';

        return \array_values(\array_unique($queryVars));
    }

    public static function registerRewriteRules(): void
    {
        $portalPath = self::getPortalBasePath();
        $loginPath = self::getPortalLoginPath();
        $portalTarget = self::getManagedPageRewriteTarget('portal_page_id');
        $loginTarget = self::getManagedPageRewriteTarget('portal_login_page_id');

        if ($portalPath !== '' && $portalTarget !== '') {
            $portalRegex = \preg_quote($portalPath, '#');
            \add_rewrite_rule(
                '^' . $portalRegex . '/?$',
                $portalTarget,
                'top'
            );
            \add_rewrite_rule(
                '^' . $portalRegex . '/([^/]+)/?$',
                $portalTarget . '&must_hotel_booking_portal_route=$matches[1]',
                'top'
            );
        }

        if ($loginPath !== '' && $loginTarget !== '') {
            $loginRegex = \preg_quote($loginPath, '#');
            \add_rewrite_rule(
                '^' . $loginRegex . '/?$',
                $loginTarget,
                'top'
            );
        }
    }

    public static function flushRewriteRules(): void
    {
        self::registerRewriteRules();
        \flush_rewrite_rules();
        \update_option(self::REWRITE_SIGNATURE_OPTION, self::getRewriteSignature());
    }

    public static function maybeRefreshRewriteRules(): void
    {
        static $didCheck = false;

        if ($didCheck) {
            return;
        }

        $didCheck = true;

        if ((string) \get_option(self::REWRITE_SIGNATURE_OPTION, '') === self::getRewriteSignature()) {
            return;
        }

        self::flushRewriteRules();
    }

    /**
     * @param array<string, mixed> $queryVars
     * @return array<string, mixed>
     */
    public static function injectPortalRequest(array $queryVars): array
    {
        if (\is_admin() || (\function_exists('wp_doing_ajax') && \wp_doing_ajax())) {
            return $queryVars;
        }

        $match = self::matchCurrentRequest();

        if (empty($match['setting_key'])) {
            return $queryVars;
        }

        unset($queryVars['error'], $queryVars['attachment'], $queryVars['attachment_id'], $queryVars['name'], $queryVars['pagename'], $queryVars['page_id']);

        return \array_merge(
            $queryVars,
            self::buildManagedPageQueryVars(
                (string) $match['setting_key'],
                (string) ($match['route'] ?? '')
            )
        );
    }

    public static function isPortalRequest(): bool
    {
        return !\is_admin() && ManagedPages::isCurrentPage('portal_page_id', 'staff');
    }

    public static function isLoginRequest(): bool
    {
        return !\is_admin() && ManagedPages::isCurrentPage('portal_login_page_id', 'staff-login');
    }

    public static function getRequestedModuleKey(): string
    {
        $route = (string) \get_query_var('must_hotel_booking_portal_route', '');

        return PortalRegistry::resolveRouteToModuleKey($route);
    }

    public static function getPortalBasePath(): string
    {
        return ManagedPages::getConfiguredPagePath('portal_page_id', 'staff');
    }

    public static function getPortalLoginPath(): string
    {
        return ManagedPages::getConfiguredPagePath('portal_login_page_id', 'staff-login');
    }

    public static function getPortalUrl(array $args = []): string
    {
        $url = ManagedPages::getPortalPageUrl();

        if ($url === '') {
            return '';
        }

        return empty($args) ? $url : \add_query_arg($args, $url);
    }

    public static function getLoginUrl(array $args = []): string
    {
        $url = ManagedPages::getPortalLoginPageUrl();

        if ($url === '') {
            return '';
        }

        return empty($args) ? $url : \add_query_arg($args, $url);
    }

    /**
     * @param array<string, scalar|int|bool> $args
     */
    public static function getModuleUrl(string $moduleKey, array $args = []): string
    {
        $definition = PortalRegistry::getDefinition($moduleKey);
        $portalUrl = self::getPortalUrl();

        if ($portalUrl === '') {
            return '';
        }

        $baseUrl = \untrailingslashit($portalUrl);

        if (!$definition) {
            return empty($args) ? $baseUrl : \add_query_arg($args, $baseUrl);
        }

        $route = (string) ($definition['route'] ?? '');
        $url = $route === '' ? $baseUrl : $baseUrl . '/' . $route;

        return empty($args) ? $url : \add_query_arg($args, $url);
    }

    /**
     * @return array<string, string>
     */
    private static function matchCurrentRequest(): array
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) \wp_unslash($_SERVER['REQUEST_URI']) : '';

        if ($requestUri === '') {
            return [];
        }

        $requestPath = \wp_parse_url($requestUri, \PHP_URL_PATH);

        if (!\is_string($requestPath) || $requestPath === '') {
            return [];
        }

        $sitePath = \wp_parse_url(\home_url('/'), \PHP_URL_PATH);
        $sitePath = \is_string($sitePath) ? \trim($sitePath, '/') : '';
        $requestPath = \trim($requestPath, '/');

        if ($sitePath !== '' && \strpos($requestPath, $sitePath . '/') === 0) {
            $requestPath = \substr($requestPath, \strlen($sitePath) + 1);
        } elseif ($sitePath !== '' && $requestPath === $sitePath) {
            $requestPath = '';
        }

        $portalPath = \trim(self::getPortalBasePath(), '/');
        $loginPath = \trim(self::getPortalLoginPath(), '/');

        if ($loginPath !== '' && $requestPath === $loginPath) {
            return [
                'setting_key' => 'portal_login_page_id',
                'route' => '',
            ];
        }

        if ($portalPath === '') {
            return [];
        }

        if ($requestPath === $portalPath) {
            return [
                'setting_key' => 'portal_page_id',
                'route' => '',
            ];
        }

        if (\strpos($requestPath, $portalPath . '/') !== 0) {
            return [];
        }

        return [
            'setting_key' => 'portal_page_id',
            'route' => \trim(\substr($requestPath, \strlen($portalPath) + 1), '/'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildManagedPageQueryVars(string $settingKey, string $route = ''): array
    {
        $queryVars = [];
        $pageId = ManagedPages::getAssignedPageId($settingKey);

        if ($pageId > 0) {
            $queryVars['page_id'] = $pageId;
        } else {
            $path = $settingKey === 'portal_login_page_id'
                ? self::getPortalLoginPath()
                : self::getPortalBasePath();

            if ($path !== '') {
                $queryVars['pagename'] = $path;
            }
        }

        if ($settingKey === 'portal_page_id') {
            $queryVars['must_hotel_booking_portal_route'] = $route;
        }

        return $queryVars;
    }

    private static function getRewriteSignature(): string
    {
        return \md5((string) \wp_json_encode([
            'schema' => self::REWRITE_SCHEMA_VERSION,
            'portal_page_id' => ManagedPages::getAssignedPageId('portal_page_id'),
            'portal_login_page_id' => ManagedPages::getAssignedPageId('portal_login_page_id'),
            'portal_path' => self::getPortalBasePath(),
            'login_path' => self::getPortalLoginPath(),
        ]));
    }

    private static function getManagedPageRewriteTarget(string $settingKey): string
    {
        $pageId = ManagedPages::getAssignedPageId($settingKey);

        if ($pageId > 0) {
            return 'index.php?page_id=' . $pageId;
        }

        $path = $settingKey === 'portal_login_page_id'
            ? self::getPortalLoginPath()
            : self::getPortalBasePath();

        return $path !== '' ? 'index.php?pagename=' . $path : '';
    }
}
