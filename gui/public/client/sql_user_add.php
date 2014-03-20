<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 *
 * The contents of this file are subject to the Mozilla Public License
 * Version 1.1 (the "License"); you may not use this file except in
 * compliance with the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS"
 * basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 * License for the specific language governing rights and limitations
 * under the License.
 *
 * The Original Code is "VHCS - Virtual Hosting Control System".
 *
 * The Initial Developer of the Original Code is moleSoftware GmbH.
 * Portions created by Initial Developer are Copyright (C) 2001-2006
 * by moleSoftware GmbH. All Rights Reserved.
 *
 * Portions created by the ispCP Team are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 *
 * Portions created by the i-MSCP Team are Copyright (C) 2010-2014 by
 * i-MSCP - internet Multi Server Control Panel. All Rights Reserved.
 *
 * @category    i-MSCP
 * @package     iMSCP_Core
 * @subpackage  Client
 * @copyright   2001-2006 by moleSoftware GmbH
 * @copyright   2006-2010 by ispCP | http://isp-control.net
 * @copyright   2010-2014 by i-MSCP | http://i-mscp.net
 * @author      ispCP Team
 * @author      i-MSCP Team
 * @link        http://i-mscp.net
 */

/**********************************************************************************************************************
 * Functions
 */

/**
 * Check SQL permissions
 *
 * @param iMSCP_pTemplate $tpl
 * @param int $databaseId Database unique identifier
 */
function client_checkSqlUserPermissions($tpl, $databaseId)
{
	$domainProperties = get_domain_default_props($_SESSION['user_id']);
	$domainSqlUsersLimit = $domainProperties['domain_sqlu_limit'];
	$limits = get_domain_running_sql_acc_cnt($domainProperties['domain_id']);

	if ($domainSqlUsersLimit != 0 && $limits[1] >= $domainSqlUsersLimit) {
		$tpl->assign('CREATE_SQLUSER', '');
	}

	$stmt = exec_query(
		'
			SELECT
				t1.domain_id
			FROM
				domain t1
			INNER JOIN
				sql_database t2 ON(t2.domain_id = t1.domain_id)
			WHERE
				t1.domain_id = ?
			AND
				t2.sqld_id = ?
			LIMIT 1
		',
		array($domainProperties['domain_id'], $databaseId)
	);

	if (!$stmt->rowCount()) {
		showBadRequestErrorPage();
	}
}

/**
 * Get list of SQL user which belong to the given database
 *
 * @param int $databaseId Database unique identifier
 * @return array
 */
function get_sqluser_list_of_current_db($databaseId)
{
	$stmt = exec_query('SELECT sqlu_name FROM sql_user WHERE sqld_id = ?', $databaseId);

	$userList = array();

	while ($row = $stmt->fetchRow(PDO::FETCH_ASSOC)) {
		$userList[] = $row['sqlu_name'];
	}

	return $userList;
}

/**
 * Get SQL user list
 *
 * @param iMSCP_pTemplate $tpl
 * @param int $sqlUserId
 * @param int $databaseId
 * @return bool
 */
function client_generateSqlUserList($tpl, $sqlUserId, $databaseId)
{
	/** @var $cfg iMSCP_Config_Handler_File */
	$cfg = iMSCP_Registry::get('config');

	$domainId = get_user_domain_id($sqlUserId);

	// Let's select all SQL users of the current domain except the users of the current database
	$stmt = exec_query(
		'
			SELECT
				t1.sqlu_name, t1.sqlu_id
			FROM
				sql_user AS t1, sql_database AS t2
			WHERE
				t1.sqld_id = t2.sqld_id
			AND
				t2.domain_id = ?
			AND
				t1.sqld_id <> ?
			ORDER BY
				t1.sqlu_name
		',
		array($domainId, $databaseId)
	);

	$firstPassed = true;
	$sqlUserFound = false;
	$prevSeenName = '';

	$userList = get_sqluser_list_of_current_db($databaseId);

	while ($row = $stmt->fetchRow(PDO::FETCH_ASSOC)) {
		// Checks if it's the first element of the combo box and set it as selected
		if ($firstPassed) {
			$select = $cfg->HTML_SELECTED;
			$firstPassed = false;
		} else {
			$select = '';
		}

		// 1. Compares the sqluser name with the record before (Is set as '' at the first time, see above)
		// 2. Compares the sqluser name with the userlist of the current database
		if ($prevSeenName != $row['sqlu_name'] && !in_array($row['sqlu_name'], $userList)) {
			$sqlUserFound = true;
			$prevSeenName = $row['sqlu_name'];

			$tpl->assign(
				array(
					'SQLUSER_ID' => $row['sqlu_id'],
					'SQLUSER_SELECTED' => $select,
					'SQLUSER_NAME' => tohtml($row['sqlu_name'])
				)
			);

			$tpl->parse('SQLUSER_LIST', '.sqluser_list');
		}
	}

	// let's hide the combobox in case there are no other sqlusers
	if (!$sqlUserFound) {
		$tpl->assign('SHOW_SQLUSER_LIST', '');
		return false;
	} else {
		return true;
	}
}

/**
 * Does the given SQL user already exists?
 *
 * @param string $sqlUser
 * @return bool
 */
function client_isSqlUser($sqlUser)
{
	$stmt = exec_query('SELECT COUNT(User) AS cnt FROM mysql.user WHERE User = ?', $sqlUser);

	return (bool)$stmt->fields['cnt'];
}

/**
 * Add SQL user for the given database
 *
 * It can be an existing user or a new user as filled out in input data
 *
 * @param int $customerId Customer unique identifier
 * @param int $databaseId Database unique identifier
 * @return mixed
 */
function client_addSqlUser($customerId, $databaseId)
{
	if (!empty($_POST)) {
		if (!isset($_POST['uaction'])) {
			showBadRequestErrorPage();
		}

		iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onBeforeAddSqlUser);

		/** @var $cfg iMSCP_Config_Handler_File */
		$cfg = iMSCP_Registry::get('config');

		$domainId = get_user_domain_id($customerId);

		if (!isset($_POST['Add_Exist'])) { // Add new SQL user as specified in input data
			if (empty($_POST['user_name'])) {
				set_page_message(tr('Please enter a username.'), 'error');
				return;
			}

			if (empty($_POST['pass'])) {
				set_page_message(tr('Please enter a password.'), 'error');
				return;
			}

			if (!isset($_POST['pass_rep']) || $_POST['pass'] !== $_POST['pass_rep']) {
				set_page_message(tr("Passwords do not match."), 'error');
				return;
			}

			if (!preg_match('/^[[:alnum:]:!*+#_.-]+$/', $_POST['pass'])) {
				set_page_message(tr("Please don't use special chars such as '@, $, %...' in the password."), 'error');
				return;
			}

			if (!checkPasswordSyntax($_POST['pass'])) {
				return;
			}

			$sqlUserPassword = $_POST['pass'];

			// we'll use domain_id in the name of the database;
			if (
				isset($_POST['use_dmn_id']) && $_POST['use_dmn_id'] == 'on' && isset($_POST['id_pos'])
				&& $_POST['id_pos'] == 'start'
			) {
				$sqlUser = $domainId . '_' . clean_input($_POST['user_name']);
			} elseif (
				isset($_POST['use_dmn_id']) && $_POST['use_dmn_id'] == 'on' && isset($_POST['id_pos']) &&
				$_POST['id_pos'] == 'end'
			) {
				$sqlUser = clean_input($_POST['user_name']) . '_' . $domainId;
			} else {
				$sqlUser = clean_input($_POST['user_name']);
			}
		} else { // Using existing SQL user as specified in input data
			$stmt = exec_query(
				'SELECT sqlu_name, sqlu_pass FROM sql_user WHERE sqlu_id = ?', intval($_POST['sqluser_id'])
			);

			if (!$stmt->rowCount()) {
				set_page_message(tr('SQL user not found.'), 'error');
				return;
			}

			$sqlUser = $stmt->fields['sqlu_name'];
			$sqlUserPassword = $stmt->fields['sqlu_pass'];
		}

		# Check for username length
		if (strlen($sqlUser) > $cfg->MAX_SQL_USER_LENGTH) {
			set_page_message(tr('User name too long!'), 'error');
			return;
		}

		// Check for unallowed character in username
		if (preg_match('/[%|\?]+/', $sqlUser)) {
			set_page_message(tr('Wildcards such as %% and ? are not allowed.'), 'error');
			return;
		}

		// Ensure that SQL user doesn't already exists
		if (!isset($_POST['Add_Exist']) && client_isSqlUser($sqlUser)) {
			set_page_message(tr('SQL username already in use.'), 'error');
			return;
		}

		# Retrieve database to which SQL user should be assigned
		$stmt = exec_query(
			'SELECT sqld_name FROM sql_database WHERE sqld_id = ? AND domain_id = ?', array($databaseId, $domainId)
		);

		if (!$stmt->rowCount()) {
			showBadRequestErrorPage();
		} else {
			$dbName = $stmt->fields['sqld_name'];
			$dbName = preg_replace('/([_%\?\*])/', '\\\$1', $dbName);

			$sqlUserCreated = false;

			// Here we cannot use transaction because the GRANT statement cause an implicit commit
			// We execute the GRANT statements first to let the i-MSCP database in clean state if one of them fails.
			try {
				$query = 'GRANT ALL PRIVILEGES ON ' . quoteIdentifier($dbName) . '.* TO ?@? IDENTIFIED BY ?';

				$sqlUserHost = $cfg['DATABASE_USER_HOST'];

				exec_query($query, array($sqlUser, $sqlUserHost, $sqlUserPassword));

				$sqlUserCreated = true;

				exec_query(
					'INSERT INTO sql_user (sqld_id, sqlu_name, sqlu_pass) VALUES (?, ?, ?)',
					array($databaseId, $sqlUser, $sqlUserPassword)
				);
			} catch (iMSCP_Exception_Database $e) {
				if ($sqlUserCreated) {
					try { // We don't care about result here - An exception is throw in case the user do not exists
						exec_query("DROP USER ?@'localhost'", $sqlUser);
					} catch (iMSCP_Exception_Database $x) {
					}
				}

				throw $e;
			}

			iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onAfterAddSqlUser);

			set_page_message(tr('SQL user successfully added.'), 'success');
			write_log(sprintf("%s added new SQL user: %s", $_SESSION['user_logged'], tohtml($sqlUser)), E_USER_NOTICE);
		}

		redirectTo('sql_manage.php');
	}
}

/**
 * @param iMSCP_pTemplate $tpl
 * @param int $databaseId
 * @return void
 */
function gen_page_post_data($tpl, $databaseId)
{
	/** @var $cfg iMSCP_Config_Handler_File */
	$cfg = iMSCP_Registry::get('config');

	if ($cfg['MYSQL_PREFIX'] == 'yes') {
		$tpl->assign('MYSQL_PREFIX_YES', '');

		if ($cfg['MYSQL_PREFIX_TYPE'] == 'behind') {
			$tpl->assign('MYSQL_PREFIX_INFRONT', '');
			$tpl->parse('MYSQL_PREFIX_BEHIND', 'mysql_prefix_behind');
			$tpl->assign('MYSQL_PREFIX_ALL', '');
		} else {
			$tpl->parse('MYSQL_PREFIX_INFRONT', 'mysql_prefix_infront');
			$tpl->assign(
				array(
					'MYSQL_PREFIX_BEHIND' => '',
					'MYSQL_PREFIX_ALL' => ''
				)
			);
		}
	} else {
		$tpl->assign(
			array(
				'MYSQL_PREFIX_NO' => '',
				'MYSQL_PREFIX_INFRONT' => '',
				'MYSQL_PREFIX_BEHIND' => ''
			)
		);
		$tpl->parse('MYSQL_PREFIX_ALL', 'mysql_prefix_all');
	}

	if (isset($_POST['uaction']) && $_POST['uaction'] == 'add_user') {
		$htmlChecked = $cfg['HTML_CHECKED'];

		$tpl->assign(
			array(
				'USER_NAME' => (isset($_POST['user_name'])) ? clean_html($_POST['user_name'], true) : '',
				'USE_DMN_ID' => (isset($_POST['use_dmn_id']) && $_POST['use_dmn_id'] === 'on') ? $htmlChecked : '',
				'START_ID_POS_SELECTED' => (isset($_POST['id_pos']) && $_POST['id_pos'] !== 'end') ? $htmlChecked : '',
				'END_ID_POS_SELECTED' => (isset($_POST['id_pos']) && $_POST['id_pos'] === 'end') ? $$htmlChecked : ''
			)
		);
	} else {
		$tpl->assign(
			array(
				'USER_NAME' => '',
				'USE_DMN_ID' => '',
				'START_ID_POS_SELECTED' => '',
				'END_ID_POS_SELECTED' => $cfg['HTML_CHECKED']
			)
		);
	}

	$tpl->assign('ID', $databaseId);
}

/***********************************************************************************************************************
 * Main
 */

// Include core library
require_once 'imscp-lib.php';

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onClientScriptStart);

check_login('user');

customerHasFeature('sql') or showBadRequestErrorPage();

if (!isset($_REQUEST['id'])) {
	showBadRequestErrorPage();
	exit;
}

$databaseId = intval($_REQUEST['id']);

/** @var $cfg iMSCP_Config_Handler_File */
$cfg = iMSCP_Registry::get('config');

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic(
	array(
		'layout' => 'shared/layouts/ui.tpl',
		'page' => 'client/sql_user_add.tpl',
		'page_message' => 'layout',
		'mysql_prefix_no' => 'page',
		'mysql_prefix_yes' => 'page',
		'mysql_prefix_infront' => 'page',
		'mysql_prefix_behind' => 'page',
		'mysql_prefix_all' => 'page',
		'sqluser_list' => 'page',
		'show_sqluser_list' => 'page',
		'create_sqluser' => 'page'
	)
);

$tpl->assign(
	array(
		'TR_PAGE_TITLE' => tr('Client / Databases / Overview / Add SQL User'),
		'ISP_LOGO' => layout_getUserLogo(),
		'TR_ADD_SQL_USER' => tr('Add SQL user'),
		'TR_USER_NAME' => tr('SQL user name'),
		'TR_USE_DMN_ID' => tr('SQL user prefix/suffix'),
		'TR_START_ID_POS' => tr('In front'),
		'TR_END_ID_POS' => tr('Behind'),
		'TR_ADD' => tr('Add'),
		'TR_CANCEL' => tr('Cancel'),
		'TR_ADD_EXIST' => tr('Assign'),
		'TR_PASS' => tr('Password'),
		'TR_PASS_REP' => tr('Repeat password'),
		'TR_SQL_USER_NAME' => tr('SQL users'),
		'TR_ASSIGN_EXISTING_SQL_USER' => tr('Assign existing SQL user'),
		'TR_NEW_SQL_USER_DATA' => tr('New SQL user data')
	)
);

client_checkSqlUserPermissions($tpl, $databaseId);
client_generateSqlUserList($tpl, $_SESSION['user_id'], $databaseId);
gen_page_post_data($tpl, $databaseId);
client_addSqlUser($_SESSION['user_id'], $databaseId);
generateNavigation($tpl);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onClientScriptEnd, array('templateEngine' => $tpl));

$tpl->prnt();

unsetMessages();
