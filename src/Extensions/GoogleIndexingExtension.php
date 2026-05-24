<?php

namespace Kraftausdruck\GoogleIndexing\Extensions;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Extension;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use App\Extensions\UrlifyExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Injector\Injector;
use GuzzleHttp\Exception\RequestException;
use Google\Auth\Credentials\ServiceAccountCredentials;

/**
 * Unified Google Indexing API extension for DataObjects.
 *
 * ⚠️  IMPORTANT: The Google Indexing API must only be used for content with
 * JobPosting or BroadcastEvent (embedded in VideoObject) structured data markup.
 * Using it for generic pages violates Google's Terms of Service and may result
 * in revocation of API access.
 *
 * Apply this extension to DataObject classes that carry JobPosting or
 * BroadcastEvent schema markup — e.g. in your app/_config/config.yml:
 *
 *   App\Models\JobPosting:
 *     extensions:
 *       - Kraftausdruck\GoogleIndexing\Extensions\GoogleIndexingExtension
 *
 * The extension auto-detects whether the owner is Versioned:
 * - Versioned (e.g. SiteTree subclass): hooks onAfterPublish / onAfterUnpublish
 * - Non-versioned with UrlifyExtension: hooks onAfterWrite / onBeforeDelete
 *
 * @extends Extension<\SilverStripe\ORM\DataObject>
 */
class GoogleIndexingExtension extends Extension
{
    private static int $timeout = 10;

    // --- Versioned hooks ---

    public function onAfterPublish(): void
    {
        $owner = $this->getOwner();

        if (!$owner->hasExtension(Versioned::class)) {
            return;
        }

        $type = $owner->hasMethod('isPubliclyVisible') && !$owner->isPubliclyVisible()
            ? 'URL_DELETED'
            : 'URL_UPDATED';

        $this->submitToGoogle($this->resolveAbsoluteLink(), $type);
    }

    public function onAfterUnpublish(): void
    {
        $owner = $this->getOwner();

        if (!$owner->hasExtension(Versioned::class)) {
            return;
        }

        $this->submitToGoogle($this->resolveAbsoluteLink(), 'URL_DELETED');
    }

    // --- Non-versioned hooks (DataObjects with UrlifyExtension) ---

    public function onAfterWrite(): void
    {
        $owner = $this->getOwner();

        if ($owner->hasExtension(Versioned::class)) {
            return; // handled by onAfterPublish
        }

        if (!$owner->hasExtension(UrlifyExtension::class)) {
            return;
        }

        // If the owner exposes isPubliclyVisible(), use it to decide the notification
        // type — URL_DELETED when inactive/expired, URL_UPDATED when live.
        // Falls back to URL_UPDATED for owners without that method.
        $type = $owner->hasMethod('isPubliclyVisible') && !$owner->isPubliclyVisible()
            ? 'URL_DELETED'
            : 'URL_UPDATED';

        $this->submitToGoogle($this->resolveAbsoluteLink(), $type);
    }

    public function onBeforeDelete(): void
    {
        $owner = $this->getOwner();

        if ($owner->hasExtension(Versioned::class)) {
            return; // handled by onAfterUnpublish
        }

        if (!$owner->hasExtension(UrlifyExtension::class)) {
            return;
        }

        $this->submitToGoogle($this->resolveAbsoluteLink(), 'URL_DELETED');
    }

    // --- Helpers ---

    private function resolveAbsoluteLink(): ?string
    {
        $owner = $this->getOwner();

        if (!$owner->hasMethod('AbsoluteLink')) {
            return null;
        }

        return call_user_func([$owner, 'AbsoluteLink']);
    }

    // --- Core submission ---

    private function submitToGoogle(?string $url, string $type): void
    {
        if (!Director::isLive()) {
            return;
        }

        if (!$url) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'GoogleIndexing: could not resolve AbsoluteLink for '
                    . get_class($this->getOwner()) . ' #' . $this->getOwner()->ID,
            );

            return;
        }

        $serviceAccountJson = Environment::getEnv('GOOGLE_INDEXING_SERVICE_ACCOUNT_JSON') ?: '';

        if (!$serviceAccountJson) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'GoogleIndexing: no service account JSON configured. '
                    . 'Set GOOGLE_INDEXING_SERVICE_ACCOUNT_JSON or configure in Settings → Google Indexing.',
            );

            return;
        }

        $jsonData = json_decode($serviceAccountJson, true);

        if (!$jsonData) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'GoogleIndexing: service account JSON is invalid.',
            );

            return;
        }

        try {
            $credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/indexing',
                $jsonData,
            );

            $token = $credentials->fetchAuthToken();
            $accessToken = $token['access_token'] ?? null;

            if (!$accessToken) {
                Injector::inst()->get(LoggerInterface::class)->warning(
                    'GoogleIndexing: failed to obtain access token.',
                );

                return;
            }

            $client = new Client(['timeout' => Config::inst()->get(self::class, 'timeout')]);
            $client->post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'url' => $url,
                    'type' => $type,
                ],
            ]);
        } catch (RequestException $exception) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'GoogleIndexing: submission failed for ' . $url . ': ' . $exception->getMessage(),
            );
        }
    }
}
