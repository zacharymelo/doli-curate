<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    ajax/tree.php
 * \ingroup dolicurate
 * \brief   Structural category operations: create, rename, move, merge, delete.
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
dol_include_once('/dolicurate/class/dolicuratetree.class.php');
dol_include_once('/dolicurate/class/dolicuratecatalog.class.php');

$action = GETPOST('action', 'aZ09') ?: 'list';

dolicurateAjaxGuard('tree', $action !== 'list');

$tree = new DoliCurateTree($db);
$catalog = new DoliCurateCatalog($db);

switch ($action) {
	case 'list':
		dolicurateJson(array('ok' => true, 'tree' => $catalog->getCategoryTree()));
		break;

	case 'create':
		$id = $tree->createCategory(
			GETPOST('label', 'alphanohtml'),
			GETPOSTINT('parent'),
			GETPOST('color', 'alphanohtml'),
			$user
		);
		if ($id <= 0) {
			dolicurateJson(array('ok' => false, 'error' => $tree->error), 400);
		}
		dolicurateJson(array('ok' => true, 'id' => $id));
		break;

	case 'update':
		// Name, colour and parent move together, so they are validated together.
		// An absent parent means "leave where it is" rather than "make it a root".
		$newParent = GETPOSTISSET('parent') ? GETPOSTINT('parent') : null;

		if ($tree->updateCategory(
			GETPOSTINT('id'),
			GETPOST('label', 'alphanohtml'),
			GETPOSTISSET('color') ? GETPOST('color', 'alphanohtml') : null,
			$newParent,
			$user
		) <= 0) {
			dolicurateJson(array('ok' => false, 'error' => $tree->error), 400);
		}
		dolicurateJson(array('ok' => true));
		break;

	case 'merge':
		$res = $tree->mergeCategories(GETPOSTINT('source'), GETPOSTINT('target'), $user);
		dolicurateJson(array(
			'ok' => !empty($res['ok']),
			'moved' => $res['moved'],
			'reparented' => $res['reparented'],
			'batch' => $res['batch'],
			'errors' => $res['errors'],
		), !empty($res['ok']) ? 200 : 409);
		break;

	case 'delete':
		$res = $tree->deleteCategory(GETPOSTINT('id'), GETPOSTINT('force') ? true : false, $user);
		dolicurateJson(array(
			'ok' => !empty($res['ok']),
			'detached' => $res['detached'],
			'batch' => $res['batch'],
			'errors' => $res['errors'],
		), !empty($res['ok']) ? 200 : 409);
		break;

	default:
		dolicurateJson(array('ok' => false, 'error' => 'UnknownAction'), 400);
}
