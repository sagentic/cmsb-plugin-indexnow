# IndexNow Plugin for CMS Builder

> **Note:** This plugin only works with CMS Builder, available for download at https://www.interactivetools.com/download/

Automatically notify search engines (Bing, Yandex, etc.) when content is created, updated, or deleted using the IndexNow protocol.

## Features

-   **Automatic Submissions**: Hooks into CMSB record save/delete events to automatically notify search engines
-   **Manual Submissions**: Submit URLs manually or bulk submit all URLs from a specific table
-   **Retry System**: Automatically retries failed submissions (429 rate limits, 5xx server errors)
-   **Logging**: Complete submission history with status tracking
-   **API Key Management**: Auto-generates API key and creates verification file
-   **Admin Settings UI**: Configure all settings through the CMS admin interface
-   **Smart URL Detection**: Handles single-record sections, permalinks, and detail pages

## Installation

1. Copy the `indexNow` folder to your plugins directory:

    - `/webadmin/plugins/indexNow/` (for this site)
    - `/cmsb/plugins/indexNow/` (standard CMSB installation)

2. Ensure PHP files have proper permissions (readable by web server):

    ```bash
    chmod 644 /path/to/plugins/indexNow/*.php
    ```

3. Log into the CMS admin area

4. The plugin will automatically:

    - Generate an API key (if not configured)
    - Create the API key verification file at site root
    - Create the log database table

5. Verify installation by visiting **Plugins > IndexNow > Dashboard**

6. Go to **Plugins > IndexNow > Settings** to select which tables to monitor

## Configuration

All settings are configured through the admin interface at **Plugins > IndexNow > Settings**.

### Admin Settings

| Setting            | Description                                       | Default |
| ------------------ | ------------------------------------------------- | ------- |
| Auto Submit        | Automatically submit URLs when content is saved   | Enabled |
| Retry Failed       | Automatically retry failed submissions            | Enabled |
| Max Retry Attempts | Maximum number of retry attempts (1-10)           | 5       |
| Log Retention      | Days to keep submission logs (1-365)              | 30      |
| Tables to Monitor  | Select which content sections trigger submissions | None    |

### Table Selection

The Settings page displays all content tables with helpful information:

-   **Section Name**: The menu name shown in the CMS
-   **Table**: The database table name
-   **Type**: Table type indicator:
    -   **Green** - Multi-record with detail pages (recommended for IndexNow)
    -   **Blue** - Multi-record with list page only
    -   **Gray** - Single-record/shared content (not recommended)
    -   **Yellow** - No public pages configured
-   **Page URL**: The configured list or detail page path

**Note:** If a table is not showing up correctly, check your Viewer URLs configuration in the CMS.

### Settings Storage

Settings are stored in `indexNow_settings.json` within the plugin folder. This file is separate from the plugin code, so your settings are preserved when updating the plugin files.

### Optional Code Configuration (Advanced)

For backwards compatibility or advanced use cases, you can also configure these globals in `indexNow.php`:

```php
// API Key - leave blank to auto-generate
$GLOBALS['INDEXNOW_API_KEY'] = '';

// IndexNow API endpoint
$GLOBALS['INDEXNOW_ENDPOINT'] = 'https://api.indexnow.org/indexnow';

// Tables to monitor (only used if no tables selected in admin settings)
$GLOBALS['INDEXNOW_TABLES'] = [];

// Tables to exclude (only used if no tables selected in admin settings)
$GLOBALS['INDEXNOW_EXCLUDE_TABLES'] = [];
```

**Note:** The admin UI settings take precedence over code configuration. The code globals are only checked if no tables are selected in the admin Settings page.

## Usage

### Dashboard

View submission statistics, API key status, and recent activity.

### Manual Submit

-   Enter URLs manually (one per line)
-   Bulk submit all URLs from a specific content table

### Settings

Configure all plugin options including which tables to monitor.

### Submission Log

View complete history of all IndexNow submissions with filtering options.

## How It Works

### Automatic URL Detection

When a record is saved or deleted, the plugin determines the public URL by checking:

1. **Permalinks**: Uses the record's `permalink` field if present
2. **Permalinks Plugin**: Checks if the Permalinks plugin provides a URL
3. **Single-Record Sections**: For `menuType = 'single'` tables, uses the list page URL (e.g., `/about/`)
4. **Detail Page**: For multi-record tables, uses `_detailPage` with the record number
5. **List Page**: Falls back to the table's `_listPage` setting

### Smart Single-Record Handling

The plugin intelligently handles single-record sections (like "About Us" pages):

-   Tables with `menuType = 'single'` return the list page URL instead of detail page
-   Tables with only one record and a list page also return the clean list page URL
-   Example: `/about/` instead of `/about/detail.php?num=4`

### IndexNow Response Codes

| Code | Meaning                          | Action                    |
| ---- | -------------------------------- | ------------------------- |
| 200  | URL submitted successfully       | Success                   |
| 202  | URL received, pending processing | Success                   |
| 400  | Bad Request - Invalid format     | Permanent fail (no retry) |
| 403  | Forbidden - Key not valid        | Permanent fail (no retry) |
| 422  | URLs don't belong to host        | Permanent fail (no retry) |
| 429  | Too Many Requests                | Retry later               |
| 5xx  | Server errors                    | Retry later               |

### Retry System

-   Failed submissions (429, 5xx) are automatically retried
-   Retries occur via CMSB's daily cron job
-   Maximum retry attempts: 5 (configurable in Settings)
-   Permanent failures (400, 403, 422) are not retried

## Table Selection Guide

**Recommended for IndexNow:**

-   Article/blog posts with individual detail pages
-   Product pages
-   Service pages
-   Any content with unique public URLs

**Not recommended for IndexNow:**

-   Shared content sections (counters, testimonials used across pages)
-   Internal data tables (accounts, settings)
-   Tables without public pages

## Version Compatibility

**Version 1.01** (Current) - For CMSB 3.82+
-   Adds support for CMSB 3.82's native .env file functionality
-   Configurable .env filename setting
-   API keys can be stored in .env files outside web root for enhanced security
-   Backward compatible with in-database API key storage

**Version 1.00** - For CMSB 3.81 and earlier
-   Does not include .env file support
-   Stores API keys in plugin settings only
-   Available for download from the GitHub releases page

## Requirements

-   CMS Builder 3.50 or higher (3.82+ recommended for .env support)
-   PHP 8.0 or higher
-   cURL extension enabled
-   Write access to web root (for API key file)

## Troubleshooting

### API Key File Not Created

-   Check that `webRootDir` is correctly set in CMS settings
-   Verify write permissions on the web root directory
-   Use the "Create Key File" button in Dashboard

### Submissions Failing with 403

-   Verify the API key file exists at site root
-   Check that the key file contains the correct API key
-   Ensure the URL host matches your domain

### URLs Not Being Detected

-   Verify the table has `_detailPage` or `_listPage` set in schema
-   Consider using the Permalinks plugin for better URL detection
-   Check that the table is enabled in Settings
-   Check your Viewer URLs configuration if a table is / is not showing up properly

### Settings Not Saving

-   Verify write permissions on the plugin directory
-   Check that `indexNow_settings.json` is writable

## File Structure

```
indexNow/
├── indexNow.php           # Main plugin file, hooks registration
├── indexNow_admin.php     # Admin UI pages (dashboard, settings, logs)
├── indexNow_functions.php # Helper functions
├── indexNow_settings.json # Settings storage (auto-created)
├── indexNow_apiKey.txt    # API key storage (auto-created)
└── README.md              # This file
```

## IndexNow Resources

-   [IndexNow Documentation](https://www.indexnow.org/documentation)
-   [IndexNow FAQ](https://www.indexnow.org/faq)
-   [Bing IndexNow](https://www.bing.com/indexnow)

## Version History

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

## Author

Sagentic Web Design
https://www.sagentic.com

## License

MIT License - See [LICENSE](LICENSE) file for details.
