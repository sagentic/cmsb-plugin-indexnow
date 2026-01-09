<?php

/**
 * IndexNow Plugin - Admin UI Pages
 *
 * @package IndexNow
 */

namespace IndexNow;

/**
 * Generate plugin navigation bar
 *
 * @param string $currentPage Current page identifier
 * @return string HTML for navigation bar
 */
function getPluginNav(string $currentPage): string
{
	$pages = [
		'dashboard' => ['label' => t('Dashboard'), 'action' => 'IndexNow\adminDashboard'],
		'manual' => ['label' => t('Manual Submit'), 'action' => 'IndexNow\adminManualSubmit'],
		'settings' => ['label' => t('Settings'), 'action' => 'IndexNow\adminSettings'],
		'log' => ['label' => t('Submission Log'), 'action' => 'IndexNow\adminSubmissionLog'],
		'help' => ['label' => t('Help'), 'action' => 'IndexNow\adminHelp'],
	];

	$html = '<nav aria-label="' . t('IndexNow plugin navigation') . '"><div class="btn-group" role="group" style="margin-bottom:20px">';
	foreach ($pages as $key => $page) {
		$isActive = ($key === $currentPage);
		$btnClass = $isActive ? 'btn btn-primary' : 'btn btn-default';
		$ariaCurrent = $isActive ? ' aria-current="page"' : '';
		$html .= '<a href="?_pluginAction=' . urlencode($page['action']) . '" class="' . $btnClass . '"' . $ariaCurrent . '>' . $page['label'] . '</a>';
	}
	$html .= '</div></nav>';

	return $html;
}

/**
 * Dashboard page - Main plugin overview
 */
function adminDashboard(): void
{
	global $SETTINGS;

	// Handle form submissions
	if (@$_REQUEST['_action'] === 'regenerateKey') {
		$newKey = generateApiKey();
		saveApiKeySetting($newKey);
		createApiKeyFile($newKey);
		\alert(t('API key regenerated successfully'));
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	if (@$_REQUEST['_action'] === 'createKeyFile') {
		$apiKey = getApiKey();
		if (createApiKeyFile($apiKey)) {
			\alert(t('API key file created successfully'));
		} else {
			\alert(t('Failed to create API key file. Check file permissions.'));
		}
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	if (@$_REQUEST['_action'] === 'clearOldLogs') {
		cleanupOldLogs();
		\alert(t('Old log entries cleared'));
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	// Get data for display
	$apiKey = getApiKey();
	$keyFileExists = apiKeyFileExists($apiKey);
	$stats = getStats();
	$recentSubmissions = getRecentSubmissions(20);

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("IndexNow"),
	];

	$adminUI['ADVANCED_ACTIONS'] = [
		t('Regenerate API Key') => '?_pluginAction=' . __FUNCTION__ . '&_action=regenerateKey',
		t('Create Key File') => '?_pluginAction=' . __FUNCTION__ . '&_action=createKeyFile',
		t('Clear Old Logs') => '?_pluginAction=' . __FUNCTION__ . '&_action=clearOldLogs',
	];

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('dashboard');

	// Load settings
	$pluginSettings = loadPluginSettings();

	// API Key Status Section
	$content .= '<div class="separator"><div>' . t('API Key Status') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// API Key
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label">' . t('API Key') . '</div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<code class="user-select-all" id="apiKeyValue">' . \htmlencode($apiKey) . '</code>';
	$content .= '<button type="button" class="btn btn-link" style="padding:0;margin-left:8px;border:none;background:none" onclick="copyToClipboard(\'' . \htmlencode($apiKey) . '\')" aria-label="' . t('Copy API key to clipboard') . '"><i class="fa-duotone fa-solid fa-copy text-info" aria-hidden="true"></i></button>';
	$content .= '</div></div>';

	// Key File
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label">' . t('Key File') . '</div>';
	$content .= '<div class="col-sm-10">';
	if ($keyFileExists) {
		$content .= '<strong style="color:#28a745"><i class="fa-duotone fa-solid fa-check" aria-hidden="true"></i> ' . t('Exists') . '</strong>';
		$content .= ' <code style="margin-left:8px">/' . \htmlencode($apiKey) . '.txt</code>';
	} else {
		$content .= '<strong style="color:#dc3545"><i class="fa-duotone fa-solid fa-xmark" aria-hidden="true"></i> ' . t('Missing') . '</strong>';
		$content .= ' <a href="?_pluginAction=' . __FUNCTION__ . '&_action=createKeyFile" class="btn btn-sm btn-warning" style="margin-left:8px">' . t('Create Now') . '</a>';
	}
	$content .= '</div></div>';

	// Auto Submit
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label">' . t('Auto Submit') . '</div>';
	$content .= '<div class="col-sm-10">';
	if ($pluginSettings['autoSubmit']) {
		$content .= '<strong style="color:#28a745"><i class="fa-duotone fa-solid fa-check" aria-hidden="true"></i> ' . t('Enabled') . '</strong>';
	} else {
		$content .= '<strong style="color:#dc3545"><i class="fa-duotone fa-solid fa-xmark" aria-hidden="true"></i> ' . t('Disabled') . '</strong>';
	}
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	// Statistics Section
	$content .= '<div class="separator"><div>' . t('Submission Statistics') . '</div></div>';

	$content .= '<div class="row g-3 mb-4">';

	// Helper function for stat card
	$renderStatCard = function ($label, $success, $failed) {
		$html = '<div class="col-6 col-lg-3">';
		$html .= '<div class="border rounded-3 p-3 h-100 text-center">';
		$html .= '<div class="text-uppercase small fw-semibold mb-3">' . $label . '</div>';
		$html .= '<div class="row">';
		// Success column
		$html .= '<div class="col-6">';
		$html .= '<div class="fs-2 fw-bold text-success">' . $success . '</div>';
		$html .= '<div class="small text-success">' . t('Success') . '</div>';
		$html .= '</div>';
		// Failed column
		$html .= '<div class="col-6">';
		$html .= '<div class="fs-2 fw-bold text-danger">' . $failed . '</div>';
		$html .= '<div class="small text-danger">' . t('Failed') . '</div>';
		$html .= '</div>';
		$html .= '</div>';
		$html .= '</div></div>';
		return $html;
	};

	$content .= $renderStatCard(t('Today'), $stats['today']['success'], $stats['today']['failed']);
	$content .= $renderStatCard(t('This Week'), $stats['week']['success'], $stats['week']['failed']);
	$content .= $renderStatCard(t('This Month'), $stats['month']['success'], $stats['month']['failed']);
	$content .= $renderStatCard(t('All Time'), $stats['total']['success'], $stats['total']['failed']);

	$content .= '</div>';

	// Recent Submissions Section
	$content .= '<div class="separator"><div>' . t('Recent Submissions') . '</div></div>';


	if (empty($recentSubmissions)) {
		$content .= '<p>' . t('No submissions yet.') . '</p>';
	} else {
		$content .= '<div class="table-responsive">';
		$content .= '<table class="table table-striped table-hover">';
		$content .= '<thead><tr>';
		$content .= '<th scope="col">' . t('Date') . '</th>';
		$content .= '<th scope="col">' . t('URL') . '</th>';
		$content .= '<th scope="col">' . t('Action') . '</th>';
		$content .= '<th scope="col">' . t('Status') . '</th>';
		$content .= '</tr></thead><tbody>';

		foreach ($recentSubmissions as $log) {
			$statusBadge = match ($log['status']) {
				'success' => '<span class="badge" style="background-color:#28a745;color:#fff">' . t('Success') . '</span>',
				'failed' => '<span class="badge" style="background-color:#ffc107;color:#212529">' . t('Retry') . '</span>',
				'permanent_fail' => '<span class="badge" style="background-color:#dc3545;color:#fff">' . t('Failed') . '</span>',
				default => '<span class="badge" style="background-color:#6c757d;color:#fff">' . t('Pending') . '</span>',
			};

			$content .= '<tr>';
			$content .= '<td class="text-nowrap">' . date('Y-m-d H:i', strtotime($log['createdDate'])) . '</td>';
			$content .= '<td class="text-truncate" style="max-width:300px" title="' . \htmlencode($log['url']) . '">' . \htmlencode($log['url']) . '</td>';
			$content .= '<td>' . \htmlencode(ucfirst($log['action'])) . '</td>';
			$content .= '<td>' . $statusBadge . '</td>';
			$content .= '</tr>';
		}

		$content .= '</tbody></table></div>';
	}

	// JavaScript for copy to clipboard
	$content .= '<script>
function copyToClipboard(text) {
	navigator.clipboard.writeText(text).then(function() {
		alert("Copied to clipboard!");
	});
}
</script>';

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Manual Submit page - Submit URLs manually
 */
function adminManualSubmit(): void
{
	$message = '';
	$messageType = 'info';
	$results = [];

	// Handle form submission
	if (@$_REQUEST['submitForm']) {
		$urlsText = trim(@$_REQUEST['urls'] ?? '');
		$submitTable = @$_REQUEST['submitTable'];

		if ($submitTable) {
			// Submit all URLs from a table
			$urls = getTableUrls($submitTable);
			if (empty($urls)) {
				$message = t('No URLs found for this table');
				$messageType = 'warning';
			} else {
				$response = submitUrls($urls, 'manual');
				if ($response['success']) {
					// Log each URL
					foreach ($urls as $url) {
						logSubmission($url, 'manual', $submitTable, null, $response);
					}
					$message = sprintf(t('Successfully submitted %d URLs from table "%s"'), count($urls), $submitTable);
					$messageType = 'success';
				} else {
					$message = sprintf(t('Failed to submit URLs: %s'), $response['message']);
					$messageType = 'danger';
				}
			}
		} elseif ($urlsText) {
			// Submit manually entered URLs
			$urls = array_filter(array_map('trim', preg_split('/[\r\n]+/', $urlsText)));
			$validUrls = [];
			$invalidUrls = [];

			foreach ($urls as $url) {
				if (validateUrl($url)) {
					$validUrls[] = $url;
				} else {
					$invalidUrls[] = $url;
				}
			}

			if (!empty($invalidUrls)) {
				$message = sprintf(
					t('Skipped %d invalid URLs (must match this domain): %s'),
					count($invalidUrls),
					implode(', ', array_slice($invalidUrls, 0, 3))
				);
				$messageType = 'warning';
			}

			if (!empty($validUrls)) {
				$response = submitUrls($validUrls, 'manual');
				if ($response['success']) {
					foreach ($validUrls as $url) {
						logSubmission($url, 'manual', null, null, $response);
					}
					$successMsg = sprintf(t('Successfully submitted %d URLs'), count($validUrls));
					$message = $message ? $message . '<br>' . $successMsg : $successMsg;
					$messageType = $response['success'] ? 'success' : $messageType;
				} else {
					$message = sprintf(t('Failed to submit URLs: %s'), $response['message']);
					$messageType = 'danger';
				}
			}
		} else {
			$message = t('Please enter URLs or select a table to submit');
			$messageType = 'warning';
		}
	}

	// Get enabled tables for dropdown (from settings)
	$settings = loadPluginSettings();
	$enabledTables = $settings['enabledTables'] ?? [];

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("IndexNow") => '?_pluginAction=IndexNow\adminDashboard',
		t("Manual Submit"),
	];

	$adminUI['FORM'] = ['name' => 'manualSubmitForm', 'autocomplete' => 'off'];
	$adminUI['HIDDEN_FIELDS'] = [
		['name' => 'submitForm', 'value' => '1'],
		['name' => '_pluginAction', 'value' => 'IndexNow\adminManualSubmit'],
	];
	$adminUI['BUTTONS'] = [
		['name' => '_action=submit', 'label' => t('Submit URLs')],
	];

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('manual');

	// Show message if any
	if ($message) {
		$content .= '<div class="alert alert-' . $messageType . '">';
		$content .= $message;
		$content .= '</div>';
	}

	// Manual URL Entry Section
	$content .= '<div class="separator"><div>' . t('Submit URLs Manually') . '</div></div>';

	$content .= '<div class="form-horizontal">';
	$content .= '<div class="form-group">';
	$content .= '<label for="urls" class="col-sm-2 control-label">' . t('URLs') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<textarea class="form-control" id="urls" name="urls" rows="6" placeholder="https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . '/page1/&#10;https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . '/page2/"></textarea>';
	$content .= '<p class="help-block" style="margin-top:8px">' . t('Enter URLs (one per line). URLs must belong to this domain.') . '</p>';
	$content .= '</div></div>';
	$content .= '</div>';

	// Submit Table URLs Section
	$content .= '<div class="separator"><div>' . t('Submit All URLs from Table') . '</div></div>';

	$content .= '<div class="form-horizontal">';
	$content .= '<div class="form-group">';
	$content .= '<label for="submitTable" class="col-sm-2 control-label">' . t('Select Table') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select class="form-control" style="width:300px;display:inline-block" id="submitTable" name="submitTable">';
	$content .= '<option value="">' . t('-- Select a table --') . '</option>';
	foreach ($enabledTables as $table) {
		$schema = \loadSchema($table);
		$menuName = $schema['menuName'] ?? $table;
		$content .= '<option value="' . \htmlencode($table) . '">' . \htmlencode($menuName) . ' (' . \htmlencode($table) . ')</option>';
	}
	$content .= '</select>';
	$content .= '<p style="margin-top:8px">' . t('This will submit all URLs from the selected table to IndexNow. Only enabled tables from Settings are shown.') . '</p>';
	$content .= '</div></div>';
	$content .= '</div>';

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Settings page - Configure plugin options
 */
function adminSettings(): void
{
	$message = '';
	$messageType = 'info';

	// Load current settings
	$settings = loadPluginSettings();

	// Handle form submission
	if (@$_REQUEST['saveSettings']) {
		// Get enabled tables from form
		$enabledTables = [];
		if (!empty($_REQUEST['enabledTables']) && is_array($_REQUEST['enabledTables'])) {
			$enabledTables = array_values(array_filter($_REQUEST['enabledTables']));
		}

		// Get custom URLs from form
		$customUrls = [];
		if (!empty($_REQUEST['customUrls']) && is_array($_REQUEST['customUrls'])) {
			foreach ($_REQUEST['customUrls'] as $table => $url) {
				$url = trim($url);
				if ($url !== '') {
					$customUrls[$table] = $url;
				}
			}
		}

		// Get default tables from form
		$defaultTables = [];
		if (!empty($_REQUEST['defaultTables']) && is_array($_REQUEST['defaultTables'])) {
			$defaultTables = array_values(array_filter($_REQUEST['defaultTables']));
		}

		// Get other settings
		$settings['enabledTables'] = $enabledTables;
		$settings['defaultTables'] = $defaultTables;
		$settings['customUrls'] = $customUrls;
		$settings['autoSubmit'] = !empty($_REQUEST['autoSubmit']);
		$settings['retryEnabled'] = !empty($_REQUEST['retryEnabled']);
		$settings['retryMaxAttempts'] = max(1, min(10, intval($_REQUEST['retryMaxAttempts'] ?? 5)));
		$settings['logRetentionDays'] = max(1, min(365, intval($_REQUEST['logRetentionDays'] ?? 30)));

		if (savePluginSettings($settings)) {
			$message = t('Settings saved successfully');
			$messageType = 'success';
		} else {
			$message = t('Failed to save settings. Check file permissions.');
			$messageType = 'danger';
		}
	}

	// Tables to completely ignore (system tables that should never appear)
	$ignoredTables = [
		'uploads',
		'accounts',
		'convert_to_webp',
	];

	// Get all content tables with their schema info
	$allTables = getContentTables();
	$selectableTables = [];
	$ignoredTablesList = [];

	foreach ($allTables as $tableName) {
		$schema = \loadSchema($tableName);
		$menuType = $schema['menuType'] ?? 'multi';
		$menuName = $schema['menuName'] ?? $tableName;
		$hasDetailPage = !empty($schema['_detailPage']);
		$hasListPage = !empty($schema['_listPage']);

		// Check if this table should be ignored
		$shouldIgnore = false;
		$ignoreReason = '';

		// Check explicit ignore list
		if (in_array($tableName, $ignoredTables)) {
			$shouldIgnore = true;
			$ignoreReason = t('System table');
		}
		// Check for _menugroup in name
		elseif (strpos($tableName, '_menugroup') !== false || strpos($tableName, 'menugroup') !== false) {
			$shouldIgnore = true;
			$ignoreReason = t('Menu group');
		}
		// Check for link menu type
		elseif ($menuType === 'link') {
			$shouldIgnore = true;
			$ignoreReason = t('Menu link');
		}
		// Check for menugroup menu type
		elseif ($menuType === 'menugroup') {
			$shouldIgnore = true;
			$ignoreReason = t('Menu group');
		}
		// Check for no public pages (no detail page AND no list page)
		elseif (!$hasDetailPage && !$hasListPage) {
			$shouldIgnore = true;
			$ignoreReason = t('No public pages');
		}

		// Determine table type description
		if ($menuType === 'single') {
			$typeDesc = t('Single-record (shared content)');
			$typeClass = 'text-muted';
		} elseif ($hasDetailPage) {
			$typeDesc = t('Multi-record with detail pages');
			$typeClass = 'text-success';
		} elseif ($hasListPage) {
			$typeDesc = t('Multi-record (list only)');
			$typeClass = 'text-info';
		} else {
			$typeDesc = t('No public pages configured');
			$typeClass = 'text-warning';
		}

		$tableData = [
			'name' => $tableName,
			'menuName' => $menuName,
			'menuType' => $menuType,
			'typeDesc' => $typeDesc,
			'typeClass' => $typeClass,
			'hasDetailPage' => $hasDetailPage,
			'hasListPage' => $hasListPage,
			'detailPage' => $schema['_detailPage'] ?? '',
			'listPage' => $schema['_listPage'] ?? '',
			'ignoreReason' => $ignoreReason,
		];

		if ($shouldIgnore) {
			$ignoredTablesList[$tableName] = $tableData;
		} else {
			$selectableTables[$tableName] = $tableData;
		}
	}

	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("IndexNow") => '?_pluginAction=IndexNow\adminDashboard',
		t("Settings"),
	];

	$adminUI['FORM'] = ['name' => 'settingsForm', 'autocomplete' => 'off'];
	$adminUI['HIDDEN_FIELDS'] = [
		['name' => 'saveSettings', 'value' => '1'],
		['name' => '_pluginAction', 'value' => 'IndexNow\adminSettings'],
	];
	$adminUI['BUTTONS'] = [
		['name' => '_action=save', 'label' => t('Save Settings')],
	];

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('settings');

	// Show message if any
	if ($message) {
		$content .= '<div class="alert alert-' . $messageType . '">';
		$content .= $message;
		$content .= '</div>';
	}

	// General Settings Section
	$content .= '<div class="separator"><div>' . t('General Settings') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Auto Submit Toggle
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label">' . t('Auto Submit') . '</div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="hidden" name="autoSubmit" value="0">';
	$content .= '<input type="checkbox" name="autoSubmit" id="autoSubmit" value="1"' . ($settings['autoSubmit'] ? ' checked' : '') . '> ';
	$content .= t('Automatically submit URLs when content is saved');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Retry Toggle
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label">' . t('Retry Failed') . '</div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="hidden" name="retryEnabled" value="0">';
	$content .= '<input type="checkbox" name="retryEnabled" id="retryEnabled" value="1"' . ($settings['retryEnabled'] ? ' checked' : '') . '> ';
	$content .= t('Automatically retry failed submissions');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Max Retry Attempts
	$content .= '<div class="form-group">';
	$content .= '<label for="retryMaxAttempts" class="col-sm-2 control-label">' . t('Max Retry Attempts') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="number" class="form-control" style="width:100px; display:inline-block" name="retryMaxAttempts" id="retryMaxAttempts" value="' . intval($settings['retryMaxAttempts']) . '" min="1" max="10">';
	$content .= '</div></div>';

	// Log Retention
	$content .= '<div class="form-group">';
	$content .= '<label for="logRetentionDays" class="col-sm-2 control-label">' . t('Log Retention') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="number" class="form-control" style="width:100px; display:inline-block" name="logRetentionDays" id="logRetentionDays" value="' . intval($settings['logRetentionDays']) . '" min="1" max="365">';
	$content .= ' <span class="help-inline">' . t('days') . '</span>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	// Tables Section
	$content .= '<div class="separator"><div>' . t('Tables to Monitor') . '</div></div>';

	$content .= '<p style="margin-bottom:15px">' . t('Select which content sections should trigger IndexNow submissions. You can also specify a custom URL for single-record sections. Be sure to check your Viewer URLs configuration if a table is / is not showing up here properly.') . '</p>';

	// Select All / None / Multi-Record buttons
	$content .= '<div style="margin-bottom:15px">';
	$content .= '<button type="button" class="btn btn-sm btn-primary" style="margin-right:5px" onclick="selectAllTables()">' . t('Select All') . '</button>';
	$content .= '<button type="button" class="btn btn-sm btn-default" style="margin-right:5px" onclick="selectNoTables()">' . t('Select None') . '</button>';
	$content .= '<button type="button" class="btn btn-sm btn-success" onclick="selectMultiRecordTables()">' . t('Select Multi-Record') . '</button>';
	$content .= '</div>';

	// Table list
	$content .= '<div class="table-responsive">';
	$content .= '<table class="table table-hover">';
	$content .= '<thead><tr>';
	$content .= '<th scope="col" style="width:50px" class="text-center">' . t('Enable') . '</th>';
	$content .= '<th scope="col">' . t('Section Name') . '</th>';
	$content .= '<th scope="col">' . t('Table') . '</th>';
	$content .= '<th scope="col">' . t('Type') . '</th>';
	$content .= '<th scope="col">' . t('Submit URL') . '</th>';
	$content .= '</tr></thead><tbody>';

	foreach ($selectableTables as $tableName => $info) {
		$isEnabled = in_array($tableName, $settings['enabledTables']);
		$customUrl = $settings['customUrls'][$tableName] ?? '';
		$defaultUrl = $info['hasDetailPage'] ? $info['detailPage'] : ($info['hasListPage'] ? $info['listPage'] : '');
		$isMultiRecord = $info['hasDetailPage'] && $info['menuType'] !== 'single';

		$content .= '<tr' . ($info['menuType'] === 'single' ? ' class="table-secondary"' : '') . '>';
		$content .= '<td class="text-center">';
		$content .= '<input class="form-check-input table-checkbox' . ($isMultiRecord ? ' multi-record' : '') . '" type="checkbox" name="enabledTables[]" value="' . \htmlencode($tableName) . '"' . ($isEnabled ? ' checked' : '') . '>';
		$content .= '</td>';
		$content .= '<td><strong>' . \htmlencode($info['menuName']) . '</strong></td>';
		$content .= '<td><code>' . \htmlencode($tableName) . '</code></td>';
		$content .= '<td><small class="' . $info['typeClass'] . '">' . $info['typeDesc'] . '</small></td>';
		$content .= '<td>';
		$content .= '<input type="text" class="form-control input-sm" style="width:250px" name="customUrls[' . \htmlencode($tableName) . ']" value="' . \htmlencode($customUrl) . '" placeholder="' . \htmlencode($defaultUrl ?: '/custom/url/') . '">';
		$content .= '</td>';
		$content .= '</tr>';
	}

	$content .= '</tbody></table>';
	$content .= '</div>';

	// Legend
	$content .= '<div style="margin-top:15px;margin-bottom:25px" class="small">';
	$content .= '<strong>' . t('Legend:') . '</strong> ';
	$content .= '<span class="text-success" style="margin-right:15px"><i class="fa-duotone fa-solid fa-circle" aria-hidden="true"></i> ' . t('Has detail pages (recommended)') . '</span>';
	$content .= '<span class="text-info" style="margin-right:15px"><i class="fa-duotone fa-solid fa-circle" aria-hidden="true"></i> ' . t('List page only') . '</span>';
	$content .= '<span class="text-muted"><i class="fa-duotone fa-solid fa-circle" aria-hidden="true"></i> ' . t('Shared content') . '</span>';
	$content .= '</div>';

	// Ignored Tables Section (if any)
	if (!empty($ignoredTablesList)) {
		$content .= '<div class="separator"><div>' . t('Excluded Tables') . '</div></div>';
		$content .= '<p style="margin-bottom:15px">' . t('These tables are automatically excluded because they are system tables, menu groups, or have no public pages. Be sure to check your Viewer URLs configuration if a table is / is not showing up here properly.') . '</p>';

		$content .= '<div class="table-responsive">';
		$content .= '<table class="table table-hover">';
		$content .= '<thead><tr>';
		$content .= '<th scope="col">' . t('Section Name') . '</th>';
		$content .= '<th scope="col">' . t('Table') . '</th>';
		$content .= '<th scope="col">' . t('Reason') . '</th>';
		$content .= '</tr></thead><tbody>';

		foreach ($ignoredTablesList as $tableName => $info) {
			$content .= '<tr class="text-muted">';
			$content .= '<td>' . \htmlencode($info['menuName']) . '</td>';
			$content .= '<td><code>' . \htmlencode($tableName) . '</code></td>';
			$content .= '<td>' . \htmlencode($info['ignoreReason']) . '</td>';
			$content .= '</tr>';
		}

		$content .= '</tbody></table>';
		$content .= '</div>';
	}

	// Default Tables for Distribution Section
	$content .= '<div class="separator"><div>' . t('Default Tables for Distribution') . '</div></div>';
	$content .= '<p style="margin-bottom:15px">' . t('Configure which tables should be pre-enabled when this plugin is installed on a new site. This is useful for developers with multiple websites that use the same tables. This can be changed for your master copy of this plugin to save you time on future builds using the same tables.') . '</p>';

	$content .= '<div class="form-horizontal">';
	$content .= '<div class="form-group">';
	$content .= '<label for="defaultTablesText" class="col-sm-2 control-label">' . t('Default Tables') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<textarea class="form-control" name="defaultTablesText" id="defaultTablesText" rows="6" placeholder="' . t('Enter table names, one per line...') . '">';
	$content .= \htmlencode(implode("\n", $settings['defaultTables'] ?? []));
	$content .= '</textarea>';
	$content .= '<p class="help-block" style="margin-top:8px">' . t('Enter table names (one per line) that should be enabled by default on new installations. These will be used when enabledTables is empty.') . '</p>';
	$content .= '</div></div>';
	$content .= '</div>';

	// Hidden inputs for defaultTables array (populated by JavaScript)
	$content .= '<div id="defaultTablesHidden"></div>';

	// JavaScript for select buttons and default tables
	$content .= '<script>
function selectAllTables() {
	document.querySelectorAll(".table-checkbox").forEach(function(cb) {
		cb.checked = true;
	});
}
function selectNoTables() {
	document.querySelectorAll(".table-checkbox").forEach(function(cb) {
		cb.checked = false;
	});
}
function selectMultiRecordTables() {
	document.querySelectorAll(".table-checkbox").forEach(function(cb) {
		cb.checked = cb.classList.contains("multi-record");
	});
}

// Convert textarea to hidden inputs before form submit
document.querySelector("form[name=settingsForm]").addEventListener("submit", function() {
	var textarea = document.getElementById("defaultTablesText");
	var container = document.getElementById("defaultTablesHidden");
	container.innerHTML = "";
	var tables = textarea.value.split("\\n").map(function(t) { return t.trim(); }).filter(function(t) { return t !== ""; });
	tables.forEach(function(table) {
		var input = document.createElement("input");
		input.type = "hidden";
		input.name = "defaultTables[]";
		input.value = table;
		container.appendChild(input);
	});
});
</script>';

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Submission Log page - View all submissions
 */
function adminSubmissionLog(): void
{
	// Pagination
	$page = max(1, intval(@$_REQUEST['page'] ?? 1));
	$perPage = intval(@$_REQUEST['perPage'] ?? 50);
	$perPage = in_array($perPage, [10, 25, 50, 100, 250]) ? $perPage : 50;
	$offset = ($page - 1) * $perPage;

	// Filters
	$filterStatus = @$_REQUEST['filterStatus'] ?? '';
	$filterAction = @$_REQUEST['filterAction'] ?? '';

	// Build where clause
	$where = "1=1";
	if ($filterStatus) {
		$where .= " AND `status` = '" . \mysql_escape($filterStatus) . "'";
	}
	if ($filterAction) {
		$where .= " AND `action` = '" . \mysql_escape($filterAction) . "'";
	}

	// Get total count
	$totalCount = \mysql_count('_indexnow_log', $where);
	$totalPages = ceil($totalCount / $perPage);

	// Get log entries
	$logs = \mysql_select('_indexnow_log', "{$where} ORDER BY `createdDate` DESC LIMIT {$offset}, {$perPage}");

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("IndexNow") => '?_pluginAction=IndexNow\adminDashboard',
		t("Submission Log"),
	];

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('log');

	// Filters Section
	$content .= '<div class="separator"><div>' . t('Filter Submissions') . '</div></div>';

	$content .= '<form method="get">';
	$content .= '<input type="hidden" name="_pluginAction" value="IndexNow\adminSubmissionLog">';
	$content .= '<div class="form-horizontal">';

	// Status filter
	$content .= '<div class="form-group">';
	$content .= '<label for="filterStatus" class="col-sm-2 control-label">' . t('Status') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select name="filterStatus" id="filterStatus" class="form-control" style="width:200px;display:inline-block">';
	$content .= '<option value="">' . t('All') . '</option>';
	$content .= '<option value="success"' . ($filterStatus === 'success' ? ' selected' : '') . '>' . t('Success') . '</option>';
	$content .= '<option value="failed"' . ($filterStatus === 'failed' ? ' selected' : '') . '>' . t('Retry Pending') . '</option>';
	$content .= '<option value="permanent_fail"' . ($filterStatus === 'permanent_fail' ? ' selected' : '') . '>' . t('Permanent Fail') . '</option>';
	$content .= '</select>';
	$content .= '</div></div>';

	// Action filter
	$content .= '<div class="form-group">';
	$content .= '<label for="filterAction" class="col-sm-2 control-label">' . t('Action') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select name="filterAction" id="filterAction" class="form-control" style="width:200px;display:inline-block">';
	$content .= '<option value="">' . t('All') . '</option>';
	$content .= '<option value="create"' . ($filterAction === 'create' ? ' selected' : '') . '>' . t('Create') . '</option>';
	$content .= '<option value="update"' . ($filterAction === 'update' ? ' selected' : '') . '>' . t('Update') . '</option>';
	$content .= '<option value="delete"' . ($filterAction === 'delete' ? ' selected' : '') . '>' . t('Delete') . '</option>';
	$content .= '<option value="manual"' . ($filterAction === 'manual' ? ' selected' : '') . '>' . t('Manual') . '</option>';
	$content .= '<option value="retry"' . ($filterAction === 'retry' ? ' selected' : '') . '>' . t('Retry') . '</option>';
	$content .= '</select>';
	$content .= '</div></div>';

	// Per page
	$content .= '<div class="form-group">';
	$content .= '<label for="perPage" class="col-sm-2 control-label">' . t('Per Page') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select name="perPage" id="perPage" class="form-control" style="width:100px;display:inline-block">';
	foreach ([10, 25, 50, 100, 250] as $pp) {
		$content .= '<option value="' . $pp . '"' . ($perPage === $pp ? ' selected' : '') . '>' . $pp . '</option>';
	}
	$content .= '</select>';
	$content .= '</div></div>';

	// Buttons
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<button type="submit" class="btn btn-primary">' . t('Filter') . '</button>';
	$content .= ' <a href="?_pluginAction=IndexNow\adminSubmissionLog" class="btn btn-default">' . t('Reset') . '</a>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal
	$content .= '</form>';

	// Results count
	$content .= '<p style="margin-top:20px">' . sprintf(t('Showing %d - %d of %d entries'), min($offset + 1, $totalCount), min($offset + $perPage, $totalCount), $totalCount) . '</p>';

	if (empty($logs)) {
		$content .= '<p>' . t('No log entries found.') . '</p>';
	} else {
		$content .= '<div class="table-responsive">';
		$content .= '<table class="table table-striped table-hover">';
		$content .= '<thead><tr>';
		$content .= '<th scope="col">' . t('Date') . '</th>';
		$content .= '<th scope="col">' . t('Table') . '</th>';
		$content .= '<th scope="col">' . t('URL') . '</th>';
		$content .= '<th scope="col">' . t('Action') . '</th>';
		$content .= '<th scope="col">' . t('Status') . '</th>';
		$content .= '<th scope="col">' . t('Response') . '</th>';
		$content .= '<th scope="col">' . t('Attempts') . '</th>';
		$content .= '</tr></thead><tbody>';

		foreach ($logs as $log) {
			$statusBadge = match ($log['status']) {
				'success' => '<span class="badge" style="background-color:#28a745;color:#fff">' . t('Success') . '</span>',
				'failed' => '<span class="badge" style="background-color:#ffc107;color:#212529">' . t('Retry') . '</span>',
				'permanent_fail' => '<span class="badge" style="background-color:#dc3545;color:#fff">' . t('Failed') . '</span>',
				default => '<span class="badge" style="background-color:#6c757d;color:#fff">' . t('Pending') . '</span>',
			};

			$content .= '<tr>';
			$content .= '<td class="text-nowrap">' . date('Y-m-d H:i:s', strtotime($log['createdDate'])) . '</td>';
			$content .= '<td>' . \htmlencode($log['tableName'] ?: '-') . '</td>';
			$content .= '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . \htmlencode($log['url']) . '">';
			$content .= '<a href="' . \htmlencode($log['url']) . '" target="_blank" rel="noopener">' . \htmlencode($log['url']) . ' <span class="sr-only">' . t('(opens in new tab)') . '</span></a></td>';
			$content .= '<td>' . \htmlencode(ucfirst($log['action'])) . '</td>';
			$content .= '<td>' . $statusBadge . '</td>';
			$content .= '<td title="' . \htmlencode($log['response_message']) . '">' . intval($log['response_code']) . '</td>';
			$content .= '<td>' . intval($log['attempts']) . '</td>';
			$content .= '</tr>';
		}

		$content .= '</tbody></table></div>';

		// Pagination
		if ($totalPages > 1) {
			$baseUrl = '?_pluginAction=IndexNow\adminSubmissionLog';
			if ($filterStatus) $baseUrl .= '&filterStatus=' . urlencode($filterStatus);
			if ($filterAction) $baseUrl .= '&filterAction=' . urlencode($filterAction);
			$baseUrl .= '&perPage=' . $perPage;

			$content .= '<div class="text-center" style="margin-top:15px">';

			// Previous
			if ($page > 1) {
				$content .= '<a href="' . $baseUrl . '&page=' . ($page - 1) . '" class="btn btn-default btn-sm">&laquo; ' . t('Previous') . '</a> ';
			}

			// Page numbers
			$startPage = max(1, $page - 2);
			$endPage = min($totalPages, $page + 2);

			for ($i = $startPage; $i <= $endPage; $i++) {
				if ($i === $page) {
					$content .= '<span class="btn btn-primary btn-sm">' . $i . '</span> ';
				} else {
					$content .= '<a href="' . $baseUrl . '&page=' . $i . '" class="btn btn-default btn-sm">' . $i . '</a> ';
				}
			}

			// Next
			if ($page < $totalPages) {
				$content .= '<a href="' . $baseUrl . '&page=' . ($page + 1) . '" class="btn btn-default btn-sm">' . t('Next') . ' &raquo;</a>';
			}

			$content .= '</div>';
		}
	}

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Help page - Display plugin documentation
 */
function adminHelp(): void
{
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("IndexNow") => '?_pluginAction=IndexNow\adminDashboard',
		t("Help"),
	];

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('help');

	// Overview Section
	$content .= '<div class="separator"><div>' . t('Overview') . '</div></div>';

	$content .= '<p>Automatically notify search engines (Bing, Yandex, etc.) when content is created, updated, or deleted using the IndexNow protocol.</p>';

	$content .= '<p><strong>' . t('Features:') . '</strong></p>';
	$content .= '<ul>';
	$content .= '<li><strong>Automatic Submissions</strong> - Hooks into record save/delete events to automatically notify search engines</li>';
	$content .= '<li><strong>Manual Submissions</strong> - Submit URLs manually or bulk submit all URLs from a specific table</li>';
	$content .= '<li><strong>Retry System</strong> - Automatically retries failed submissions (429 rate limits, 5xx server errors)</li>';
	$content .= '<li><strong>Logging</strong> - Complete submission history with status tracking</li>';
	$content .= '<li><strong>API Key Management</strong> - Auto-generates API key and creates verification file</li>';
	$content .= '<li><strong>Smart URL Detection</strong> - Handles single-record sections, permalinks, and detail pages</li>';
	$content .= '</ul>';

	// Installation Section
	$content .= '<div class="separator"><div>' . t('Installation') . '</div></div>';

	$content .= '<ol>';
	$content .= '<li>Copy the <code>indexNow</code> folder to your plugins directory</li>';
	$content .= '<li>Ensure PHP files have proper permissions: <code>chmod 644 /path/to/plugins/indexNow/*.php</code></li>';
	$content .= '<li>Log into the CMSB admin area and navigate to the Plugins menu</li>';
	$content .= '<li>The plugin will automatically generate an API key and create the verification file</li>';
	$content .= '<li>Verify installation by visiting <strong>Plugins &gt; IndexNow &gt; Dashboard</strong></li>';
	$content .= '<li>Go to <strong>Plugins &gt; IndexNow &gt; Settings</strong> to select which tables to monitor</li>';
	$content .= '</ol>';

	// Configuration Section
	$content .= '<div class="separator"><div>' . t('Configuration') . '</div></div>';

	$content .= '<p>All settings are configured through the admin interface at <strong>Plugins &gt; IndexNow &gt; Settings</strong>.</p>';

	$content .= '<div class="table-responsive">';
	$content .= '<table class="table table-striped">';
	$content .= '<thead><tr><th>' . t('Setting') . '</th><th>' . t('Description') . '</th><th>' . t('Default') . '</th></tr></thead>';
	$content .= '<tbody>';
	$content .= '<tr><td>Auto Submit</td><td>Automatically submit URLs when content is saved</td><td>Enabled</td></tr>';
	$content .= '<tr><td>Retry Failed</td><td>Automatically retry failed submissions</td><td>Enabled</td></tr>';
	$content .= '<tr><td>Max Retry Attempts</td><td>Maximum number of retry attempts (1-10)</td><td>5</td></tr>';
	$content .= '<tr><td>Log Retention</td><td>Days to keep submission logs (1-365)</td><td>30</td></tr>';
	$content .= '<tr><td>Tables to Monitor</td><td>Select which content sections trigger submissions</td><td>None</td></tr>';
	$content .= '</tbody></table>';
	$content .= '</div>';

	// Table Selection Section
	$content .= '<div class="separator" style="margin-top:25px"><div>' . t('Table Selection Guide') . '</div></div>';

	$content .= '<p>The Settings page displays all content tables with type indicators:</p>';

	$content .= '<ul>';
	$content .= '<li><span class="text-success"><strong>Green</strong></span> - Multi-record with detail pages (recommended for IndexNow)</li>';
	$content .= '<li><span class="text-info"><strong>Blue</strong></span> - Multi-record with list page only</li>';
	$content .= '<li><span class="text-muted"><strong>Gray</strong></span> - Single-record/shared content (not recommended)</li>';
	$content .= '<li><span class="text-warning"><strong>Yellow</strong></span> - No public pages configured</li>';
	$content .= '</ul>';

	$content .= '<p><strong>Recommended for IndexNow:</strong> Article/blog posts, product pages, service pages, and any content with unique public URLs.</p>';
	$content .= '<p><strong>Not recommended:</strong> Shared content sections (counters, testimonials), internal data tables, or tables without public pages.</p>';

	// How It Works Section
	$content .= '<div class="separator"><div>' . t('How It Works') . '</div></div>';

	$content .= '<p>When a record is saved or deleted, the plugin determines the public URL by checking:</p>';
	$content .= '<ol>';
	$content .= '<li><strong>Permalinks</strong> - Uses the record\'s <code>permalink</code> field if present</li>';
	$content .= '<li><strong>Permalinks Plugin</strong> - Checks if the Permalinks plugin provides a URL</li>';
	$content .= '<li><strong>Single-Record Sections</strong> - Uses the list page URL (e.g., <code>/about/</code>)</li>';
	$content .= '<li><strong>Detail Page</strong> - Uses <code>_detailPage</code> with the record number</li>';
	$content .= '<li><strong>List Page</strong> - Falls back to the table\'s <code>_listPage</code> setting</li>';
	$content .= '</ol>';

	// Response Codes Section
	$content .= '<div class="separator"><div>' . t('IndexNow Response Codes') . '</div></div>';

	$content .= '<div class="table-responsive" style="margin-bottom:20px">';
	$content .= '<table class="table table-striped">';
	$content .= '<thead><tr><th>' . t('Code') . '</th><th>' . t('Meaning') . '</th><th>' . t('Action') . '</th></tr></thead>';
	$content .= '<tbody>';
	$content .= '<tr><td>200</td><td>URL submitted successfully</td><td><strong style="color:#28a745;text-transform:uppercase;font-size:11px">Success</strong></td></tr>';
	$content .= '<tr><td>202</td><td>URL received, pending processing</td><td><strong style="color:#28a745;text-transform:uppercase;font-size:11px">Success</strong></td></tr>';
	$content .= '<tr><td>400</td><td>Bad Request - Invalid format</td><td><strong style="color:#dc3545;text-transform:uppercase;font-size:11px">Permanent Fail</strong></td></tr>';
	$content .= '<tr><td>403</td><td>Forbidden - Key not valid</td><td><strong style="color:#dc3545;text-transform:uppercase;font-size:11px">Permanent Fail</strong></td></tr>';
	$content .= '<tr><td>422</td><td>URLs don\'t belong to host</td><td><strong style="color:#dc3545;text-transform:uppercase;font-size:11px">Permanent Fail</strong></td></tr>';
	$content .= '<tr><td>429</td><td>Too Many Requests</td><td><strong style="color:#e67e00;text-transform:uppercase;font-size:11px">Retry Later</strong></td></tr>';
	$content .= '<tr><td>5xx</td><td>Server errors</td><td><strong style="color:#e67e00;text-transform:uppercase;font-size:11px">Retry Later</strong></td></tr>';
	$content .= '</tbody></table>';
	$content .= '</div>';

	// Troubleshooting Section
	$content .= '<div class="separator" style="margin-top:30px"><div>' . t('Troubleshooting') . '</div></div>';

	$content .= '<p><strong>API Key File Not Created</strong></p>';
	$content .= '<ul>';
	$content .= '<li>Check that <code>webRootDir</code> is correctly set in CMS settings</li>';
	$content .= '<li>Verify write permissions on the web root directory</li>';
	$content .= '<li>Use the "Create Key File" button in Dashboard</li>';
	$content .= '</ul>';

	$content .= '<p><strong>Submissions Failing with 403</strong></p>';
	$content .= '<ul>';
	$content .= '<li>Verify the API key file exists at site root</li>';
	$content .= '<li>Check that the key file contains the correct API key</li>';
	$content .= '<li>Ensure the URL host matches your domain</li>';
	$content .= '</ul>';

	$content .= '<p><strong>URLs Not Being Detected</strong></p>';
	$content .= '<ul>';
	$content .= '<li>Verify the table has <code>_detailPage</code> or <code>_listPage</code> set in schema</li>';
	$content .= '<li>Consider using the Permalinks plugin for better URL detection</li>';
	$content .= '<li>Check that the table is enabled in Settings</li>';
	$content .= '</ul>';

	// Requirements Section
	$content .= '<div class="separator"><div>' . t('Requirements') . '</div></div>';

	$content .= '<ul>';
	$content .= '<li>CMS Builder 3.50 or higher</li>';
	$content .= '<li>PHP 8.0 or higher</li>';
	$content .= '<li>cURL extension enabled</li>';
	$content .= '<li>Write access to web root (for API key file)</li>';
	$content .= '</ul>';

	// Resources Section
	$content .= '<div class="separator"><div>' . t('Resources') . '</div></div>';

	$content .= '<ul>';
	$content .= '<li><a href="https://www.indexnow.org/documentation" target="_blank" rel="noopener">IndexNow Documentation <span class="sr-only">' . t('(opens in new tab)') . '</span></a></li>';
	$content .= '<li><a href="https://www.indexnow.org/faq" target="_blank" rel="noopener">IndexNow FAQ <span class="sr-only">' . t('(opens in new tab)') . '</span></a></li>';
	$content .= '<li><a href="https://www.bing.com/indexnow" target="_blank" rel="noopener">Bing IndexNow <span class="sr-only">' . t('(opens in new tab)') . '</span></a></li>';
	$content .= '</ul>';

	// Version Info
	$content .= '<div class="separator"><div>' . t('Version Information') . '</div></div>';

	$content .= '<p><strong>Version:</strong> ' . ($GLOBALS['INDEXNOW_VERSION'] ?? '1.00') . '</p>';
	$content .= '<p><strong>Author:</strong> <a href="https://www.sagentic.com" target="_blank" rel="noopener">Sagentic Web Design <span class="sr-only">' . t('(opens in new tab)') . '</span></a></p>';

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}
