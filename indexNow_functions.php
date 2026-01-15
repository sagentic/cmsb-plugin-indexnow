<?php

/**
 * IndexNow Plugin - Helper Functions
 *
 * @package IndexNow
 */

namespace IndexNow;

/**
 * Get the path to the settings JSON file
 *
 * @return string Settings file path
 */
function getSettingsFilePath(): string
{
	return __DIR__ . '/indexNow_settings.json';
}

/**
 * Load plugin settings from JSON file
 *
 * @return array Settings array
 */
function loadPluginSettings(): array
{
	$settingsFile = getSettingsFilePath();
	$defaults = [
		'enabledTables' => [],
		'defaultTables' => [], // Tables to pre-select on new installations
		'customUrls' => [], // Table => custom URL mapping for single-record sections
		'autoSubmit' => true,
		'retryEnabled' => true,
		'retryMaxAttempts' => 5,
		'logRetentionDays' => 30,
		'envFilename' => '.env.cms.php', // Default env filename
	];

	if (!file_exists($settingsFile) || !is_readable($settingsFile)) {
		return $defaults;
	}

	$content = @file_get_contents($settingsFile);
	if ($content === false) {
		return $defaults;
	}

	$settings = @json_decode($content, true);

	if (!is_array($settings)) {
		return $defaults;
	}

	$merged = array_merge($defaults, $settings);

	// If enabledTables is empty but defaultTables has values, use defaultTables
	// This allows distributors to set sensible defaults for new installations
	if (empty($merged['enabledTables']) && !empty($merged['defaultTables'])) {
		$merged['enabledTables'] = $merged['defaultTables'];
	}

	return $merged;
}

/**
 * Save plugin settings to JSON file
 *
 * @param array $settings Settings to save
 * @return bool True on success
 */
function savePluginSettings(array $settings): bool
{
	$settingsFile = getSettingsFilePath();
	$json = json_encode($settings, JSON_PRETTY_PRINT);
	return @file_put_contents($settingsFile, $json) !== false;
}

/**
 * Check if a table should be monitored for IndexNow submissions
 *
 * @param string $tableName The table name to check
 * @return bool True if table should be monitored
 */
function shouldMonitorTable(string $tableName): bool
{
	// Skip system tables (starting with _)
	if (str_starts_with($tableName, '_')) {
		return false;
	}

	// Load settings from JSON file
	$settings = loadPluginSettings();
	$enabledTables = $settings['enabledTables'] ?? [];

	// If no tables configured, check legacy globals for backwards compatibility
	if (empty($enabledTables)) {
		// Skip explicitly excluded tables (legacy)
		if (in_array($tableName, $GLOBALS['INDEXNOW_EXCLUDE_TABLES'] ?? [])) {
			return false;
		}

		// If specific tables defined in legacy config, only monitor those
		if (!empty($GLOBALS['INDEXNOW_TABLES'])) {
			return in_array($tableName, $GLOBALS['INDEXNOW_TABLES']);
		}

		// Default: don't auto-submit if no tables configured
		return false;
	}

	// Check if table is in enabled list
	return in_array($tableName, $enabledTables);
}

/**
 * Check if a table is a single-record table (content used across multiple pages)
 *
 * @param string $tableName Table name to check
 * @return bool True if single-record table
 */
function isSingleRecordTable(string $tableName): bool
{
	// Check settings first
	$settings = loadPluginSettings();
	$singleRecordTables = $settings['singleRecordTables'] ?? [];

	if (in_array($tableName, $singleRecordTables)) {
		return true;
	}

	// Also check schema for menuType = 'single'
	$schema = \loadSchema($tableName);
	return ($schema['menuType'] ?? '') === 'single';
}

/**
 * Get the public URL for a record
 *
 * @param string $tableName Table name
 * @param array $record Record data
 * @return string|null URL or null if cannot be determined
 */
function getRecordUrl(string $tableName, array $record): ?string
{
	$baseUrl = getBaseUrl();
	$schema = \loadSchema($tableName);
	$settings = loadPluginSettings();

	// Check for permalink first (if record has one)
	if (!empty($record['permalink'])) {
		return $baseUrl . '/' . ltrim($record['permalink'], '/') . '/';
	}

	// Check if permalinks plugin provides a function
	if (function_exists('permalinks_getPermalink')) {
		$permalink = \permalinks_getPermalink($tableName, $record['num']);
		if ($permalink) {
			return $baseUrl . '/' . ltrim($permalink, '/') . '/';
		}
	}

	// Check for custom URL override (used for tables without individual permalinks)
	if (!empty($settings['customUrls'][$tableName])) {
		$customUrl = $settings['customUrls'][$tableName];
		if (!str_starts_with($customUrl, 'http')) {
			$customUrl = $baseUrl . '/' . ltrim($customUrl, '/');
		}
		return rtrim($customUrl, '/') . '/';
	}

	// For single-record sections (menuType = 'single'), use the list page URL
	// These sections hold content for a page, not individual detail pages
	$isSingleRecord = ($schema['menuType'] ?? '') === 'single';

	if ($isSingleRecord) {
		// Single-record sections don't have individual detail pages
		// Use the list page if available
		if (!empty($schema['_listPage'])) {
			$listPage = $schema['_listPage'];
			if (!str_starts_with($listPage, 'http')) {
				$listPage = $baseUrl . '/' . ltrim($listPage, '/');
			}
			// Return clean URL without query parameters
			return rtrim(preg_replace('/\/index\.php$/', '/', $listPage), '/') . '/';
		}
		// No dedicated page for this content
		return null;
	}

	// For multi-record sections with detail pages
	if (!empty($schema['_detailPage'])) {
		$detailPage = $schema['_detailPage'];
		// Handle relative URLs
		if (!str_starts_with($detailPage, 'http')) {
			$detailPage = $baseUrl . '/' . ltrim($detailPage, '/');
		}

		// Check if this is a single-record section based on record count
		// If there's only ever one record and it uses a detail page, use the list page instead
		$recordCount = \mysql_count($tableName, "1=1");
		if ($recordCount <= 1 && !empty($schema['_listPage'])) {
			$listPage = $schema['_listPage'];
			if (!str_starts_with($listPage, 'http')) {
				$listPage = $baseUrl . '/' . ltrim($listPage, '/');
			}
			// Return clean URL: /about/ instead of /about/detail.php?num=4
			return rtrim(preg_replace('/\/index\.php$/', '/', $listPage), '/') . '/';
		}

		// Multi-record: add record num parameter
		$separator = str_contains($detailPage, '?') ? '&' : '?';
		return $detailPage . $separator . 'num=' . $record['num'];
	}

	// Fall back to list page
	if (!empty($schema['_listPage'])) {
		$listPage = $schema['_listPage'];
		if (!str_starts_with($listPage, 'http')) {
			$listPage = $baseUrl . '/' . ltrim($listPage, '/');
		}
		return rtrim(preg_replace('/\/index\.php$/', '/', $listPage), '/') . '/';
	}

	// Cannot determine URL
	return null;
}

/**
 * Get URL for a deleted record (using permalinks database if available)
 *
 * @param string $tableName Table name
 * @param int $recordNum Record number
 * @return string|null URL or null if cannot be determined
 */
function getRecordUrlForDelete(string $tableName, int $recordNum): ?string
{
	global $TABLE_PREFIX;

	$baseUrl = getBaseUrl();

	// Try to get from permalinks table if it exists
	$permalinksTableExists = \mysql_count('_permalinks', "1=1") !== false;
	if ($permalinksTableExists) {
		$whereEtc = \mysql_escapef(
			"`tableName` = ? AND `recordNum` = ? AND `old` = '0'",
			$tableName,
			$recordNum
		);
		$permalink = \mysql_get('_permalinks', null, $whereEtc);
		if ($permalink && !empty($permalink['permalink'])) {
			return $baseUrl . '/' . $permalink['permalink'] . '/';
		}
	}

	// Try to construct from schema
	$schema = \loadSchema($tableName);
	if (!empty($schema['_detailPage'])) {
		$detailPage = $schema['_detailPage'];
		if (!str_starts_with($detailPage, 'http')) {
			$detailPage = $baseUrl . '/' . ltrim($detailPage, '/');
		}
		$separator = str_contains($detailPage, '?') ? '&' : '?';
		return $detailPage . $separator . 'num=' . $recordNum;
	}

	return null;
}

/**
 * Get the site's base URL
 *
 * @return string Base URL without trailing slash
 */
function getBaseUrl(): string
{
	$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
	return $protocol . '://' . $host;
}

/**
 * Get or generate the API key
 *
 * @return string API key
 */
function getApiKey(): string
{
	// Use configured key if provided
	if (!empty($GLOBALS['INDEXNOW_API_KEY'])) {
		return $GLOBALS['INDEXNOW_API_KEY'];
	}

	// Check environment file first (more secure)
	$envKey = \CMS::env('APP_INDEXNOW_API_KEY');
	if ($envKey) {
		return $envKey;
	}

	// Check if we have a stored key in settings
	$storedKey = \settings('indexnow_api_key');
	if ($storedKey) {
		return $storedKey;
	}

	// Generate a new key
	$newKey = generateApiKey();

	// Store it in settings
	saveApiKeySetting($newKey);

	return $newKey;
}

/**
 * Generate a new API key (32 hex characters)
 *
 * @return string Generated API key
 */
function generateApiKey(): string
{
	return bin2hex(random_bytes(16));
}

/**
 * Save API key to CMS settings or .env file
 *
 * @param string $apiKey API key to save
 */
function saveApiKeySetting(string $apiKey): void
{
	$pluginSettings = loadPluginSettings();
	$useEnvStorage = $pluginSettings['useEnvStorage'] ?? false;

	// Save to .env.php if configured
	if ($useEnvStorage && saveApiKeyToEnv($apiKey)) {
		// Successfully saved to .env, clear from settings if present
		global $SETTINGS;
		if (isset($SETTINGS['indexnow_api_key'])) {
			$SETTINGS['indexnow_api_key'] = '';
			saveSettings();
		}
		return;
	}

	// Fall back to settings storage
	global $SETTINGS;
	$SETTINGS['indexnow_api_key'] = $apiKey;
	saveSettings();
}

/**
 * Save API key to .env.php file (more secure option)
 *
 * @param string $apiKey API key to save
 * @return bool True on success, false on failure
 */
function saveApiKeyToEnv(string $apiKey): bool
{
	// Get the _dotEnvPath from settings
	$envPath = \settings('_dotEnvPath');
	if (!$envPath) {
		return false; // No .env path configured
	}

	// Get configured filename from plugin settings
	$pluginSettings = loadPluginSettings();
	$envFilename = $pluginSettings['envFilename'] ?? '.env.cms.php';

	// If envPath is just a filename, use it as-is
	// Otherwise, replace the filename part with the configured filename
	if (basename($envPath) !== $envPath) {
		// It's a path - replace just the filename
		$envPath = dirname($envPath) . '/' . $envFilename;
	} else {
		// It's just a filename already
		$envPath = $envFilename;
	}

	// Resolve absolute path
	$absPath = str_starts_with($envPath, '.') ? CMS_DIR . '/' . $envPath : $envPath;

	// Don't use realpath yet - the file might not exist
	if (!file_exists($absPath)) {
		return false; // File doesn't exist
	}

	$absPath = realpath($absPath);

	// Load current .env contents
	$env = include $absPath;
	if (!is_array($env)) {
		return false; // Invalid format
	}

	// Update the API key
	$env['APP_INDEXNOW_API_KEY'] = $apiKey;

	// Write back to file
	$content = "<?php\n";
	$content .= "/**\n";
	$content .= " * Environment Secrets File\n";
	$content .= " *\n";
	$content .= " * Keeps sensitive credentials out of data/settings*.php files.\n";
	$content .= " * This file should be backed up separately and never committed to git.\n";
	$content .= " */\n";
	$content .= "return [\n\n";
	$content .= "    /**\n";
	$content .= "     * CMS SETTINGS\n";
	$content .= "     * -------------------------------------------------------------------------\n";
	$content .= "     * These keys override related values in settings.*.php\n";
	$content .= "     */\n\n";

	// Write each key-value pair
	foreach ($env as $key => $value) {
		// Add section headers
		if ($key === 'DB_HOSTNAME') {
			$content .= "    // Database\n";
		} elseif ($key === 'SMTP_HOSTNAME') {
			$content .= "\n    // Outgoing Mail\n";
		} elseif (str_starts_with($key, 'APP_') && !isset($lastAppKey)) {
			$content .= "\n    /**\n";
			$content .= "     * CUSTOM VALUES\n";
			$content .= "     * -------------------------------------------------------------------------\n";
			$content .= "     * Add your own keys for plugins, APIs, etc.\n";
			$content .= "     * Access with: \\CMS::env('APP_MY_KEY')\n";
			$content .= "     * Prefix with APP_ to avoid conflicts with future CMS keys.\n";
			$content .= "     */\n\n";
			$lastAppKey = true;
		}

		// Format the value
		$formattedValue = var_export($value, true);
		if (is_string($value)) {
			$formattedValue = "'" . addslashes($value) . "'";
		}

		// Add key-value pair with proper spacing
		$padding = str_repeat(' ', max(1, 31 - strlen($key)));
		$content .= "    '{$key}'{$padding}=> {$formattedValue},\n";
	}

	$content .= "\n];\n";

	// Write to file
	return @file_put_contents($absPath, $content) !== false;
}

/**
 * Create the API key verification file at site root
 *
 * @param string $apiKey API key
 * @return bool True on success
 */
function createApiKeyFile(string $apiKey): bool
{
	$webRootDir = \settings('webRootDir');
	if (!$webRootDir || !is_dir($webRootDir)) {
		return false;
	}

	$keyFilePath = rtrim($webRootDir, '/') . '/' . $apiKey . '.txt';

	// Check if file already exists with correct content
	if (file_exists($keyFilePath)) {
		$existingContent = trim(file_get_contents($keyFilePath));
		if ($existingContent === $apiKey) {
			return true; // Already correct
		}
	}

	// Create the file
	$result = file_put_contents($keyFilePath, $apiKey);
	return $result !== false;
}

/**
 * Check if API key file exists and is valid
 *
 * @param string $apiKey API key to check
 * @return bool True if file exists and is valid
 */
function apiKeyFileExists(string $apiKey): bool
{
	$webRootDir = \settings('webRootDir');
	if (!$webRootDir) {
		return false;
	}

	$keyFilePath = rtrim($webRootDir, '/') . '/' . $apiKey . '.txt';

	if (!file_exists($keyFilePath)) {
		return false;
	}

	$content = trim(file_get_contents($keyFilePath));
	return $content === $apiKey;
}

/**
 * Submit URL(s) to IndexNow API
 *
 * @param string|array $urls Single URL or array of URLs
 * @param string $action Action type (create, update, delete, manual, retry)
 * @param string|null $tableName Table name (for logging)
 * @param int|null $recordNum Record number (for logging)
 * @return array Response with 'success', 'code', 'message' keys
 */
function submitUrl($urls, string $action = 'manual', ?string $tableName = null, ?int $recordNum = null): array
{
	// Normalize to array
	if (!is_array($urls)) {
		$urls = [$urls];
	}

	$urls = array_filter($urls); // Remove empty values
	if (empty($urls)) {
		return ['success' => false, 'code' => 0, 'message' => 'No URLs provided'];
	}

	$apiKey = getApiKey();
	$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
	$keyLocation = "https://{$host}/{$apiKey}.txt";
	$endpoint = $GLOBALS['INDEXNOW_ENDPOINT'];

	// For single URL, use GET request
	if (count($urls) === 1) {
		$url = urlencode($urls[0]);
		$requestUrl = "{$endpoint}?url={$url}&key={$apiKey}";
		$response = httpRequest($requestUrl, 'GET');
	}
	// For multiple URLs, use POST with JSON body
	else {
		$payload = json_encode([
			'host' => $host,
			'key' => $apiKey,
			'keyLocation' => $keyLocation,
			'urlList' => array_values($urls)
		]);

		$response = httpRequest(
			$endpoint,
			'POST',
			$payload,
			['Content-Type: application/json; charset=utf-8']
		);
	}

	return $response;
}

/**
 * Submit multiple URLs in batch (for sitemap submission, etc.)
 *
 * @param array $urls Array of URLs
 * @param string $action Action type
 * @return array Response with 'success', 'code', 'message' keys
 */
function submitUrls(array $urls, string $action = 'manual'): array
{
	// IndexNow allows up to 10,000 URLs per request
	$batchSize = 10000;
	$results = [];

	$batches = array_chunk($urls, $batchSize);
	foreach ($batches as $batch) {
		$response = submitUrl($batch, $action);
		$results[] = $response;

		// If any batch fails, return that failure
		if (!$response['success']) {
			return $response;
		}

		// Small delay between batches to avoid rate limiting
		if (count($batches) > 1) {
			usleep(100000); // 100ms
		}
	}

	// Return success if all batches succeeded
	return ['success' => true, 'code' => 200, 'message' => 'All URLs submitted successfully'];
}

/**
 * Make HTTP request to IndexNow API
 *
 * @param string $url Request URL
 * @param string $method HTTP method (GET or POST)
 * @param string|null $body Request body for POST
 * @param array $headers Additional headers
 * @return array Response with 'success', 'code', 'message' keys
 */
function httpRequest(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
	$ch = curl_init();

	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 3,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_USERAGENT => 'CMSB-IndexNow-Plugin/1.0',
	]);

	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		if ($body) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
	}

	if (!empty($headers)) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}

	$responseBody = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	// Handle curl errors
	if ($curlError) {
		return [
			'success' => false,
			'code' => 0,
			'message' => 'Connection error: ' . $curlError
		];
	}

	// Interpret response codes
	$success = in_array($httpCode, [200, 202]);
	$message = getResponseMessage($httpCode, $responseBody);

	return [
		'success' => $success,
		'code' => $httpCode,
		'message' => $message
	];
}

/**
 * Get human-readable message for IndexNow response code
 *
 * @param int $code HTTP response code
 * @param string $body Response body
 * @return string Message
 */
function getResponseMessage(int $code, string $body = ''): string
{
	$messages = [
		200 => 'URL submitted successfully',
		202 => 'URL received, pending processing',
		400 => 'Bad Request - Invalid format',
		403 => 'Forbidden - API key not valid for this URL',
		422 => 'Unprocessable Entity - URLs do not belong to host',
		429 => 'Too Many Requests - Rate limited',
		500 => 'Internal Server Error',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
	];

	if (isset($messages[$code])) {
		return $messages[$code];
	}

	if ($code >= 500) {
		return "Server Error (HTTP {$code})";
	}

	if ($code >= 400) {
		return "Client Error (HTTP {$code})";
	}

	return "Unknown response (HTTP {$code})";
}

/**
 * Check if a response code indicates a permanent failure (should not retry)
 *
 * @param int $responseCode HTTP response code
 * @return bool True if permanent failure
 */
function isPermanentFailure(int $responseCode): bool
{
	$permanentFailureCodes = [400, 403, 422];
	return in_array($responseCode, $permanentFailureCodes);
}

/**
 * Create the log table if it doesn't exist
 */
function createLogTableIfNeeded(): void
{
	global $TABLE_PREFIX;

	// Check if table exists
	$tableExists = false;
	$result = \mysqli()->query("SHOW TABLES LIKE '{$TABLE_PREFIX}_indexnow_log'");
	if ($result && $result->num_rows > 0) {
		$tableExists = true;
	}

	if ($tableExists) {
		return;
	}

	// Create the table
	$sql = "CREATE TABLE IF NOT EXISTS `{$TABLE_PREFIX}_indexnow_log` (
		`num` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`createdDate` datetime DEFAULT NULL,
		`tableName` varchar(255) DEFAULT NULL,
		`recordNum` int(10) unsigned DEFAULT NULL,
		`url` text,
		`action` enum('create','update','delete','manual','retry') DEFAULT NULL,
		`status` enum('pending','success','failed','permanent_fail') DEFAULT 'pending',
		`response_code` int(5) DEFAULT NULL,
		`response_message` text,
		`attempts` int(3) DEFAULT 1,
		`last_attempt` datetime DEFAULT NULL,
		`next_retry` datetime DEFAULT NULL,
		PRIMARY KEY (`num`),
		KEY `createdDate` (`createdDate`),
		KEY `status` (`status`),
		KEY `next_retry` (`next_retry`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

	\mysqli()->query($sql);
}

/**
 * Log a submission to the database
 *
 * @param string $url Submitted URL
 * @param string $action Action type
 * @param string|null $tableName Table name
 * @param int|null $recordNum Record number
 * @param array $response API response
 */
function logSubmission(string $url, string $action, ?string $tableName, ?int $recordNum, array $response): void
{
	global $TABLE_PREFIX;

	$settings = loadPluginSettings();
	$status = $response['success'] ? 'success' : (isPermanentFailure($response['code']) ? 'permanent_fail' : 'failed');

	// Set next retry time for failed submissions
	$nextRetry = null;
	if ($status === 'failed' && $settings['retryEnabled']) {
		$nextRetry = date('Y-m-d H:i:s', strtotime('+12 hours'));
	}

	$data = [
		'createdDate=' => 'NOW()',
		'tableName' => $tableName,
		'url' => $url,
		'action' => $action,
		'status' => $status,
		'response_code' => $response['code'],
		'response_message' => $response['message'],
		'attempts' => 1,
		'last_attempt=' => 'NOW()',
	];

	// Handle nullable recordNum
	if ($recordNum !== null) {
		$data['recordNum'] = $recordNum;
	} else {
		$data['recordNum='] = 'NULL';
	}

	// Handle nullable next_retry
	if ($nextRetry) {
		$data['next_retry'] = $nextRetry;
	} else {
		$data['next_retry='] = 'NULL';
	}

	\mysql_insert('_indexnow_log', $data);
}

/**
 * Update log entry status
 *
 * @param int $logNum Log entry number
 * @param string $status New status
 * @param array $response API response
 * @param int $attempts Total attempts
 * @param string|null $nextRetry Next retry time
 */
function updateLogStatus(int $logNum, string $status, array $response, int $attempts, ?string $nextRetry = null): void
{
	$data = [
		'status' => $status,
		'response_code' => $response['code'],
		'response_message' => $response['message'],
		'attempts' => $attempts,
		'last_attempt=' => 'NOW()',
	];

	// Only set next_retry if we have a value (NULL causes MySQL errors)
	if ($nextRetry) {
		$data['next_retry'] = $nextRetry;
	} else {
		$data['next_retry='] = 'NULL';
	}

	\mysql_update('_indexnow_log', $logNum, null, $data);
}

/**
 * Get submission statistics
 *
 * @return array Statistics array
 */
function getStats(): array
{
	global $TABLE_PREFIX;

	$stats = [
		'today' => ['success' => 0, 'failed' => 0, 'pending' => 0],
		'week' => ['success' => 0, 'failed' => 0, 'pending' => 0],
		'month' => ['success' => 0, 'failed' => 0, 'pending' => 0],
		'total' => ['success' => 0, 'failed' => 0, 'pending' => 0],
	];

	// Today
	$todayStart = date('Y-m-d 00:00:00');
	$stats['today']['success'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` = 'success' AND `createdDate` >= '{$todayStart}'"
	);
	$stats['today']['failed'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` IN ('failed', 'permanent_fail') AND `createdDate` >= '{$todayStart}'"
	);
	$stats['today']['pending'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` = 'pending' AND `createdDate` >= '{$todayStart}'"
	);

	// This week
	$weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
	$stats['week']['success'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` = 'success' AND `createdDate` >= '{$weekStart}'"
	);
	$stats['week']['failed'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` IN ('failed', 'permanent_fail') AND `createdDate` >= '{$weekStart}'"
	);
	$stats['week']['pending'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` = 'pending' AND `createdDate` >= '{$weekStart}'"
	);

	// This month
	$monthStart = date('Y-m-01 00:00:00');
	$stats['month']['success'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` = 'success' AND `createdDate` >= '{$monthStart}'"
	);
	$stats['month']['failed'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` IN ('failed', 'permanent_fail') AND `createdDate` >= '{$monthStart}'"
	);
	$stats['month']['pending'] = (int)\mysql_count(
		'_indexnow_log',
		"`status` = 'pending' AND `createdDate` >= '{$monthStart}'"
	);

	// Total
	$stats['total']['success'] = (int)\mysql_count('_indexnow_log', "`status` = 'success'");
	$stats['total']['failed'] = (int)\mysql_count('_indexnow_log', "`status` IN ('failed', 'permanent_fail')");
	$stats['total']['pending'] = (int)\mysql_count('_indexnow_log', "`status` = 'pending'");

	return $stats;
}

/**
 * Get recent submissions log
 *
 * @param int $limit Number of entries to retrieve
 * @return array Log entries
 */
function getRecentSubmissions(int $limit = 50): array
{
	return \mysql_select('_indexnow_log', "1=1 ORDER BY `createdDate` DESC LIMIT {$limit}");
}

/**
 * Validate a URL is safe to submit (belongs to this host)
 *
 * @param string $url URL to validate
 * @return bool True if valid
 */
function validateUrl(string $url): bool
{
	// Must be a valid URL
	if (!filter_var($url, FILTER_VALIDATE_URL)) {
		return false;
	}

	// Must be HTTP or HTTPS
	$scheme = parse_url($url, PHP_URL_SCHEME);
	if (!in_array(strtolower($scheme), ['http', 'https'])) {
		return false;
	}

	// Must match current host
	$urlHost = parse_url($url, PHP_URL_HOST);
	$currentHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

	// Allow with or without www
	$urlHost = preg_replace('/^www\./', '', strtolower($urlHost));
	$currentHost = preg_replace('/^www\./', '', strtolower($currentHost));

	return $urlHost === $currentHost;
}

/**
 * Get list of content tables (non-system tables)
 *
 * @return array List of table names
 */
function getContentTables(): array
{
	$tableNames = \getSchemaTables();
	$contentTables = [];

	foreach ($tableNames as $tableName) {
		// Skip system tables (starting with _)
		if (str_starts_with($tableName, '_')) {
			continue;
		}
		$contentTables[] = $tableName;
	}

	sort($contentTables);
	return $contentTables;
}

/**
 * Get all URLs from a table (for bulk submission)
 * Returns unique URLs only (handles tables without detail pages where all records share one URL)
 *
 * @param string $tableName Table name
 * @return array URLs (deduplicated)
 */
function getTableUrls(string $tableName): array
{
	$urls = [];
	$records = \mysql_select($tableName, "1=1");

	foreach ($records as $record) {
		$url = getRecordUrl($tableName, $record);
		if ($url) {
			$urls[] = $url;
		}
	}

	// Remove duplicates (e.g., multi-record tables without detail pages all return the same list page URL)
	return array_values(array_unique($urls));
}

/**
 * Mask API key for display (show first/last 4 chars)
 * Note: This function is no longer used as we now display the full key to admins.
 * Kept for backwards compatibility or future use.
 *
 * @param string $apiKey API key
 * @return string Masked key
 */
function maskApiKey(string $apiKey): string
{
	if (strlen($apiKey) <= 8) {
		return str_repeat('*', strlen($apiKey));
	}
	return substr($apiKey, 0, 4) . str_repeat('*', strlen($apiKey) - 8) . substr($apiKey, -4);
}
