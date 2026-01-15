<?php
/*
Plugin Name: IndexNow
Description: Automatically notify search engines when content changes using the IndexNow protocol
Version: 1.01
CMS Version Required: 3.50
Author: Sagentic Web Design
Author URI: https://www.sagentic.com
*/

namespace IndexNow;

// Don't run from command-line
if (inCLI()) {
	return;
}

// Legacy Configuration - These are kept for backwards compatibility
// Settings are now managed via the admin Settings page and stored in indexNow_settings.json
$GLOBALS['INDEXNOW_API_KEY']              = '';      // Leave blank to auto-generate
$GLOBALS['INDEXNOW_ENDPOINT']             = 'https://api.indexnow.org/indexnow';
$GLOBALS['INDEXNOW_TABLES']               = array(); // Legacy: Use Settings page instead
$GLOBALS['INDEXNOW_EXCLUDE_TABLES']       = array('accounts'); // Legacy: Use Settings page instead

// DON'T UPDATE ANYTHING BELOW THIS LINE

$GLOBALS['INDEXNOW_PLUGIN'] = true;
$GLOBALS['INDEXNOW_VERSION'] = '1.01';

// Load helper functions
require_once __DIR__ . '/indexNow_functions.php';

// Register hooks for record changes (settings checked at runtime inside onRecordSave/onRecordDelete)
addAction('record_postsave', 'IndexNow\onRecordSave', null, 4);
addAction('record_posterase', 'IndexNow\onRecordDelete', null, 4);

// Register cron job for retries
addAction('_cron_daily', 'IndexNow\processRetries', null, 0);

// Register cron job for log cleanup
addAction('_cron_daily', 'IndexNow\cleanupOldLogs', null, 0);

// Initialize plugin (create table, API key file, etc.)
addAction('admin_postlogin', 'IndexNow\pluginInit', null, -999);

// Admin UI - only load when in admin area
if (defined('IS_CMS_ADMIN')) {
	require_once __DIR__ . '/indexNow_admin.php';

	// Register plugin menu pages
	pluginAction_addHandlerAndLink(t('Dashboard'), 'IndexNow\adminDashboard', 'admins');
	pluginAction_addHandlerAndLink(t('Manual Submit'), 'IndexNow\adminManualSubmit', 'admins');
	pluginAction_addHandlerAndLink(t('Settings'), 'IndexNow\adminSettings', 'admins');
	pluginAction_addHandlerAndLink(t('Submission Log'), 'IndexNow\adminSubmissionLog', 'admins');
	pluginAction_addHandlerAndLink(t('Help'), 'IndexNow\adminHelp', 'admins');
}

/**
 * Initialize plugin - create database table and API key file if needed
 */
function pluginInit(): void
{
	// Create log table if it doesn't exist
	createLogTableIfNeeded();

	// Ensure API key exists and key file is created
	$apiKey = getApiKey();
	if ($apiKey) {
		createApiKeyFile($apiKey);
	}
}

/**
 * Hook: Called after a record is saved (created or updated)
 */
function onRecordSave(): void
{
	global $tableName, $isNewRecord;

	// Check if auto-submit is enabled
	$settings = loadPluginSettings();
	if (!$settings['autoSubmit']) {
		return;
	}

	// Check if we should monitor this table
	if (!shouldMonitorTable($tableName)) {
		return;
	}

	// Get record number from request
	$recordNum = intval(@$_REQUEST['num']);
	if (!$recordNum) {
		return;
	}

	// Fetch the record from database
	$record = \mysql_get($tableName, $recordNum);
	if (!$record) {
		return;
	}

	// Get the URL for this record
	$url = getRecordUrl($tableName, $record);
	if (!$url) {
		return; // Can't determine URL, skip submission
	}

	// Determine action type
	$action = $isNewRecord ? 'create' : 'update';

	// Submit to IndexNow
	$response = submitUrl($url, $action, $tableName, $recordNum);

	// Log the submission
	logSubmission($url, $action, $tableName, $recordNum, $response);
}

/**
 * Hook: Called after a record is deleted
 */
function onRecordDelete(): void
{
	global $tableName;

	// Check if auto-submit is enabled
	$settings = loadPluginSettings();
	if (!$settings['autoSubmit']) {
		return;
	}

	// Check if we should monitor this table
	if (!shouldMonitorTable($tableName)) {
		return;
	}

	// Get deleted record nums from request
	$selectedRecords = @$_REQUEST['selectedRecords'] ?: [];
	if (!is_array($selectedRecords)) {
		$selectedRecords = [$selectedRecords];
	}

	foreach ($selectedRecords as $recordNum) {
		$recordNum = intval($recordNum);
		if (!$recordNum) {
			continue;
		}

		// For deletes, we need to try to construct the URL from available data
		// Since the record is deleted, we'll try to get it from permalinks or schema
		$url = getRecordUrlForDelete($tableName, $recordNum);
		if (!$url) {
			continue; // Can't determine URL, skip submission
		}

		// Submit to IndexNow (notifying that this URL has changed/been removed)
		$response = submitUrl($url, 'delete', $tableName, $recordNum);

		// Log the submission
		logSubmission($url, 'delete', $tableName, $recordNum, $response);
	}
}

/**
 * Process failed submissions (retry system)
 * Called by daily cron
 */
function processRetries(): void
{
	global $TABLE_PREFIX;

	$settings = loadPluginSettings();

	if (!$settings['retryEnabled']) {
		return;
	}

	$maxAttempts = $settings['retryMaxAttempts'];

	// Get failed submissions that need retry
	$query = "SELECT * FROM `{$TABLE_PREFIX}_indexnow_log`
			  WHERE `status` = 'failed'
			  AND `attempts` < ?
			  AND (`next_retry` IS NULL OR `next_retry` <= NOW())
			  ORDER BY `createdDate` ASC
			  LIMIT 100";

	$failedSubmissions = \mysql_select_query($query, [$maxAttempts]);

	foreach ($failedSubmissions as $submission) {
		$response = submitUrl($submission['url'], 'retry');
		$newAttempts = $submission['attempts'] + 1;

		if ($response['success']) {
			// Mark as success
			updateLogStatus($submission['num'], 'success', $response, $newAttempts);
		} elseif (isPermanentFailure($response['code'])) {
			// Mark as permanent failure - no more retries
			updateLogStatus($submission['num'], 'permanent_fail', $response, $newAttempts);
		} elseif ($newAttempts >= $maxAttempts) {
			// Max attempts reached
			updateLogStatus($submission['num'], 'permanent_fail', $response, $newAttempts);
		} else {
			// Schedule next retry (12 hours from now for twice-daily)
			$nextRetry = date('Y-m-d H:i:s', strtotime('+12 hours'));
			updateLogStatus($submission['num'], 'failed', $response, $newAttempts, $nextRetry);
		}
	}
}

/**
 * Cleanup old log entries based on retention setting
 * Called by daily cron
 */
function cleanupOldLogs(): void
{
	global $TABLE_PREFIX;

	$settings = loadPluginSettings();
	$retentionDays = $settings['logRetentionDays'];
	if ($retentionDays <= 0) {
		return; // Retention disabled
	}

	$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

	$query = "DELETE FROM `{$TABLE_PREFIX}_indexnow_log` WHERE `createdDate` < ?";
	\mysqli()->query(\mysql_escapef($query, $cutoffDate));
}
