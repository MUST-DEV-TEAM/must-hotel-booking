<?php

namespace MustHotelBooking\Core;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Updater
{
    /** @var object|null */
    private static $updateChecker = null;

    public static function boot(): void
    {
        if (self::$updateChecker !== null) {
            return;
        }

        if (!self::isEnabled()) {
            return;
        }

        $libraryFile = MUST_HOTEL_BOOKING_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

        if (!\is_file($libraryFile)) {
            return;
        }

        require_once $libraryFile;

        $repositoryUrl = self::getRepositoryUrl();

        if (!self::isRepositoryConfigured($repositoryUrl)) {
            return;
        }

        $updateChecker = PucFactory::buildUpdateChecker(
            $repositoryUrl,
            MUST_HOTEL_BOOKING_FILE,
            MUST_HOTEL_BOOKING_PLUGIN_SLUG
        );

        $updateChecker->setBranch(self::getBranch());

        $token = self::getToken();

        if ($token !== '') {
            $updateChecker->setAuthentication($token);
        }

        $assetPattern = self::getReleaseAssetPattern();

        if ($assetPattern !== '') {
            $updateChecker->getVcsApi()->enableReleaseAssets($assetPattern);
        } else {
            $updateChecker->getVcsApi()->enableReleaseAssets();
        }

        self::$updateChecker = $updateChecker;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getStatus(): array
    {
        $repositoryUrl = self::getRepositoryUrl();

        return [
            'enabled' => self::isEnabled(),
            'configured' => self::isRepositoryConfigured($repositoryUrl),
            'repository' => $repositoryUrl,
            'branch' => self::getBranch(),
            'plugin_slug' => MUST_HOTEL_BOOKING_PLUGIN_SLUG,
            'release_asset_pattern' => self::getReleaseAssetPattern(),
            'version' => MUST_HOTEL_BOOKING_VERSION,
            'library_loaded' => self::$updateChecker !== null,
        ];
    }

    private static function isEnabled(): bool
    {
        return \defined('MUST_HOTEL_BOOKING_UPDATER_ENABLED')
            && (bool) MUST_HOTEL_BOOKING_UPDATER_ENABLED;
    }

    private static function getRepositoryUrl(): string
    {
        return \trailingslashit(\trim((string) MUST_HOTEL_BOOKING_GITHUB_REPOSITORY));
    }

    private static function getBranch(): string
    {
        $branch = \trim((string) MUST_HOTEL_BOOKING_GITHUB_BRANCH);

        return $branch !== '' ? $branch : 'main';
    }

    private static function getReleaseAssetPattern(): string
    {
        return (string) MUST_HOTEL_BOOKING_GITHUB_RELEASE_ASSET_PATTERN;
    }

    private static function getToken(): string
    {
        return \trim((string) MUST_HOTEL_BOOKING_GITHUB_TOKEN);
    }

    private static function isRepositoryConfigured(string $repositoryUrl): bool
    {
        if ($repositoryUrl === '') {
            return false;
        }

        if (\strpos($repositoryUrl, 'replace-with-your-org') !== false) {
            return false;
        }

        return \filter_var($repositoryUrl, \FILTER_VALIDATE_URL) !== false;
    }
}
