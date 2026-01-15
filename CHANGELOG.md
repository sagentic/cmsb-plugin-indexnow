# Changelog

## Version 1.01 (2026-01-14)

-   Added optional .env.php support for secure API key storage
-   API key can now be stored in .env.php file (outside web root) instead of settings.dat.php
-   New setting: "Store API key in .env.php file (more secure)"
-   Added configurable .env filename setting
-   New "Env Filename" field in Settings allows customizing the environment file name
-   Supports any filename (e.g., .env.cms.php, .env.php, .env)
-   Automatically uses configured filename when saving API keys to environment file
-   Automatic migration of API key between storage methods
-   Backward compatible - works with or without .env configured
-   Enhanced security for sites using environment file storage

## Version 1.00 (2026-01-07)

-   Initial release
-   Automatic URL submissions on record save/delete
-   Manual URL submission (single or bulk by table)
-   Smart URL detection for permalinks, single-record sections, and detail pages
-   Retry system for temporary failures (429 rate limits, 5xx server errors)
-   Submission logging with statistics
-   Admin Dashboard with API key management
-   Settings page for table selection and configuration
-   Help page with documentation
-   Settings stored in JSON file (preserved during plugin updates)
-   MIT License
