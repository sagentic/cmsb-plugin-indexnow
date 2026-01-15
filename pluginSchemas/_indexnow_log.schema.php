<?php

/**
 * IndexNow Log Table Schema
 *
 * Note: This schema file is provided for reference but the table is
 * created directly via SQL in the plugin for simplicity.
 */
return [
	'menuName'            => 'IndexNow Log',
	'_tableName'          => '_indexnow_log',
	'_primaryKey'         => 'num',
	'menuType'            => 'multi',
	'listPageFields'      => 'createdDate, url, action, status, response_code',
	'listPageOrder'       => 'createdDate DESC',
	'listPageSearchFields' => '_all_',
	'_filenameFields'     => 'num',
	'_disableView'        => 1,
	'menuHidden'          => 1,
	'menuOrder'           => 9999999999,

	'num' => [
		'type'          => 'none',
		'label'         => 'Record Number',
		'isSystemField' => 1,
	],

	'createdDate' => [
		'type'          => 'none',
		'label'         => 'Created Date',
		'isSystemField' => 1,
	],

	'tableName' => [
		'label'            => 'Table Name',
		'type'             => 'textfield',
		'customColumnType' => 'VARCHAR(255)',
	],

	'recordNum' => [
		'label'            => 'Record Number',
		'type'             => 'textfield',
		'customColumnType' => 'INT(10) UNSIGNED',
	],

	'url' => [
		'label'            => 'URL',
		'type'             => 'textbox',
		'customColumnType' => 'TEXT',
	],

	'action' => [
		'label'            => 'Action',
		'type'             => 'list',
		'listType'         => 'pulldown',
		'optionsType'      => 'text',
		'optionsText'      => "create|Create\nupdate|Update\ndelete|Delete\nmanual|Manual\nretry|Retry",
		'customColumnType' => "ENUM('create','update','delete','manual','retry')",
	],

	'status' => [
		'label'            => 'Status',
		'type'             => 'list',
		'listType'         => 'pulldown',
		'optionsType'      => 'text',
		'optionsText'      => "pending|Pending\nsuccess|Success\nfailed|Failed\npermanent_fail|Permanent Fail",
		'defaultValue'     => 'pending',
		'customColumnType' => "ENUM('pending','success','failed','permanent_fail') DEFAULT 'pending'",
	],

	'response_code' => [
		'label'            => 'Response Code',
		'type'             => 'textfield',
		'customColumnType' => 'INT(5)',
	],

	'response_message' => [
		'label'            => 'Response Message',
		'type'             => 'textbox',
		'customColumnType' => 'TEXT',
	],

	'attempts' => [
		'label'            => 'Attempts',
		'type'             => 'textfield',
		'defaultValue'     => '1',
		'customColumnType' => 'INT(3) DEFAULT 1',
	],

	'last_attempt' => [
		'label'            => 'Last Attempt',
		'type'             => 'none',
		'customColumnType' => 'DATETIME',
	],

	'next_retry' => [
		'label'            => 'Next Retry',
		'type'             => 'none',
		'customColumnType' => 'DATETIME',
		'indexed'          => 1,
	],
];

