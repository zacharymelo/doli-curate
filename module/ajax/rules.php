<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    ajax/rules.php
 * \ingroup dolicurate
 * \brief   Rule set CRUD, live preview, and application.
 *
 * Reads are GET; anything that writes is POST and CSRF-checked.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
	$res = @include '../../../../main.inc.php';
}
if (!$res) {
	http_response_code(500);
	exit;
}

dol_include_once('/dolicurate/lib/dolicurate.lib.php');
dol_include_once('/dolicurate/class/dolicuraterules.class.php');
dol_include_once('/dolicurate/class/dolicuratecatalog.class.php');

// decorateValueLabels() translates Product/Service/Unknown for display.
$langs->loadLangs(array('dolicurate@dolicurate', 'products', 'main'));

$action = GETPOST('action', 'aZ09') ?: 'list';
$readOnly = in_array($action, array('list', 'get', 'preview'), true);

dolicurateAjaxGuard('rules', !$readOnly);

$rules = new DoliCurateRules($db);

switch ($action) {
	case 'list':
		// Suppliers travel with the list so the rule editor can offer a real
		// picker instead of asking the user to know a thirdparty id.
		$catalog = new DoliCurateCatalog($db);
		dolicurateJson(array(
			'ok' => true,
			'rulesets' => $rules->listRuleSets(),
			'matchtypes' => DoliCurateRules::matchTypes(),
			'suppliers' => $catalog->listSuppliers(),
		));
		break;

	case 'get':
		$set = $rules->getRuleSet(GETPOSTINT('id'));
		if (!$set) {
			dolicurateJson(array('ok' => false, 'error' => 'RuleSetNotFound'), 404);
		}
		dolicurateJson(array('ok' => true, 'ruleset' => $set));
		break;

	case 'preview':
		$res = $rules->previewRuleSet(GETPOSTINT('id'), getDolGlobalInt('DOLICURATE_PREVIEW_LIMIT', 200));
		if (empty($res['ok'])) {
			dolicurateJson(array('ok' => false, 'error' => $rules->error), 404);
		}
		dolicurateJson($res);
		break;

	case 'createset':
		$id = $rules->createRuleSet(
			GETPOST('label', 'alphanohtml'),
			GETPOST('description', 'restricthtml'),
			GETPOSTINT('only_untagged'),
			$user
		);
		if ($id <= 0) {
			dolicurateJson(array('ok' => false, 'error' => $rules->error), 400);
		}
		dolicurateJson(array('ok' => true, 'id' => $id));
		break;

	case 'deleteset':
		if ($rules->deleteRuleSet(GETPOSTINT('id')) <= 0) {
			dolicurateJson(array('ok' => false, 'error' => $rules->error), 400);
		}
		dolicurateJson(array('ok' => true));
		break;

	case 'addrule':
		$id = $rules->addRule(
			GETPOSTINT('ruleset'),
			GETPOSTINT('match_type'),
			GETPOST('match_value', 'alphanohtml'),
			GETPOSTINT('category')
		);
		if ($id <= 0) {
			dolicurateJson(array('ok' => false, 'error' => $rules->error), 400);
		}
		dolicurateJson(array('ok' => true, 'id' => $id));
		break;

	case 'deleterule':
		if ($rules->deleteRule(GETPOSTINT('id')) <= 0) {
			dolicurateJson(array('ok' => false, 'error' => $rules->error), 400);
		}
		dolicurateJson(array('ok' => true));
		break;

	case 'apply':
		$res = $rules->applyRuleSet(GETPOSTINT('id'), $user);
		dolicurateJson(array(
			'ok' => !empty($res['ok']),
			'batch' => isset($res['batch']) ? $res['batch'] : '',
			'applied' => isset($res['applied']) ? $res['applied'] : 0,
			'skipped' => isset($res['skipped']) ? $res['skipped'] : 0,
			'failed' => isset($res['failed']) ? $res['failed'] : 0,
			'errors' => isset($res['errors']) ? $res['errors'] : array(),
		), !empty($res['ok']) ? 200 : 409);
		break;

	default:
		dolicurateJson(array('ok' => false, 'error' => 'UnknownAction'), 400);
}
