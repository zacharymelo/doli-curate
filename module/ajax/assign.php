<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    ajax/assign.php
 * \ingroup dolicurate
 * \brief   Add or remove categories for a set of products, and undo batches.
 *
 * POST only, CSRF-checked by main.inc.php. Every write goes through
 * DoliCurateCurator so it lands in the audit trail.
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
dol_include_once('/dolicurate/class/dolicuratecurator.class.php');

$action = GETPOST('action', 'aZ09');

// Undo is a separate right: reversing someone else's batch is not the same
// permission as tagging a product.
dolicurateAjaxGuard($action === 'undo' ? 'undo' : 'assign', true);

$curator = new DoliCurateCurator($db);

if ($action === 'undo') {
	$batch = GETPOST('batch', 'alphanohtml');
	if (!preg_match('/^[0-9a-f]{32}$/', (string) $batch)) {
		dolicurateJson(array('ok' => false, 'error' => 'BadBatchId'), 400);
	}

	$res = $curator->undoBatch($batch, $user);
	dolicurateJson(array(
		'ok' => !empty($res['ok']),
		'reversed' => isset($res['reversed']) ? $res['reversed'] : 0,
		'applied' => $res['applied'],
		'skipped' => $res['skipped'],
		'errors' => $res['errors'],
	), !empty($res['ok']) ? 200 : 409);
}

$products = json_decode(GETPOST('products', 'restricthtml'), true);
$categories = json_decode(GETPOST('categories', 'restricthtml'), true);

if (!is_array($products) || !is_array($categories) || empty($products) || empty($categories)) {
	dolicurateJson(array('ok' => false, 'error' => 'NothingSelected'), 400);
}

if ($action === 'add') {
	$res = $curator->assign($products, $categories, $user, DoliCurateCurator::SOURCE_MANUAL);
} elseif ($action === 'remove') {
	$res = $curator->unassign($products, $categories, $user, DoliCurateCurator::SOURCE_MANUAL);
} else {
	dolicurateJson(array('ok' => false, 'error' => 'UnknownAction'), 400);
}

dolicurateJson(array(
	'ok' => !empty($res['ok']),
	'batch' => $res['batch'],
	'applied' => $res['applied'],
	'skipped' => $res['skipped'],
	'failed' => $res['failed'],
	'errors' => $res['errors'],
), !empty($res['ok']) ? 200 : 409);
