# silverstripe-google-indexing

Google Indexing API integration for Silverstripe CMS.

> ⚠️ **The Google Indexing API is restricted to pages with [JobPosting](https://developers.google.com/search/docs/appearance/structured-data/job-posting) or [BroadcastEvent](https://developers.google.com/search/docs/appearance/structured-data/video) structured data markup. Using it for other content violates Google's Terms of Service and may result in API access revocation.**

## Requirements

- Silverstripe CMS ^6
- `google/auth` ^1.0

## Installation

```bash
composer require lerni/silverstripe-google-indexing
```

## Google Cloud setup

1. Enable the **Indexing API** in [Google Cloud Console](https://console.cloud.google.com/)
2. Create a **Service Account** and download the JSON key file
3. Add the service account email as an **owner** in [Google Search Console](https://search.google.com/search-console) for your property

## Configuration

Set the service account JSON via environment variable (recommended):

```env
GOOGLE_INDEXING_SERVICE_ACCOUNT_JSON='{"type":"service_account","project_id":"…"}'
```

## Usage

Apply `GoogleIndexingExtension` only to DataObject classes with JobPosting or BroadcastEvent schema markup:

```yaml
# app/_config/config.yml
App\Models\JobPosting:
  extensions:
    - Kraftausdruck\GoogleIndexing\Extensions\GoogleIndexingExtension
```

The extension auto-detects the owner's versioning:

- **Versioned** (e.g. SiteTree subclass) → notifies Google on `onAfterPublish` / `onAfterUnpublish`
- **Non-versioned with UrlifyExtension** → notifies Google on `onAfterWrite` / `onBeforeDelete`

Submissions only happen in **live** environments (`Director::isLive()`). Nothing is sent in dev or test.

### Optional: `isPubliclyVisible()`

By default, every write/publish triggers `URL_UPDATED`. If your DataObject has additional visibility conditions beyond its published state (e.g. an `Active` flag or a `ValidThrough` expiry date), implement `isPubliclyVisible(): bool`:

```php
public function isPubliclyVisible(): bool
{
    return (bool) $this->Active && (bool) $this->ValidThrough >= date('Y-m-d');
}
```

When this method exists, the extension sends `URL_DELETED` instead of `URL_UPDATED` whenever it returns `false` — correctly signalling to Google that the URL is no longer publicly accessible.

## Timeout

Default HTTP timeout is 10 seconds. Override via config:

```yaml
Kraftausdruck\GoogleIndexing\Extensions\GoogleIndexingExtension:
  timeout: 15
```

## License

BSD-3-Clause © Lukas Erni
