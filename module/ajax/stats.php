<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    ajax/stats.php
 * \ingroup dolicurate
 * \brief   Dashboard figures and the batch history feed.
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
dol_include_once('/dolicurate/class/dolicuratecatalog.class.php');
dol_include_once('/dolicurate/class/dolicuratecurator.class.php');

dolicurateAjaxGuard('read');

$action = GETPOST('action', 'aZ09') ?: 'coverage';
$catalog = new DoliCurateCatalog($db);

if ($action === 'history') {
	$curator = new DoliCurateCurator($db);
	dolicurateJson(array('ok' => true, 'batches' => $curator->listBatches(GETPOSTINT('limit') ?: 50)));
}

if ($action === 'batch') {
	$curator = new DoliCurateCurator($db);
	$detail = $curator->getBatchDetail(
		GETPOST('batch', 'alphanohtml'),
		GETPOSTINT('limit') ?: 200,
		GETPOSTINT('offset')
	);
	if (empty($detail['rows']) && $curator->error) {
		dolicurateJson(array('ok' => false, 'error' => $curator->error), 400);
	}
	dolicurateJson(array(
		'ok' => true,
		'rows' => $detail['rows'],
		'total' => $detail['total'],
		'productUrl' => dol_buildpath('/product/card.php', 1),
		'categoryUrl' => dol_buildpath('/categories/viewcat.php', 1),
	));
}

if ($action === 'tree') {
	dolicurateJson(array('ok' => true, 'tree' => $catalog->getCategoryTree()));
}

dolicurateJson(array(
	'ok' => true,
	'coverage' => $catalog->getCoverage(),
	'tree' => $catalog->getCategoryTree(),
));
