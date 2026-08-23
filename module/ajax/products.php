<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    ajax/products.php
 * \ingroup dolicurate
 * \brief   Worklist feed: a filtered, paged page of products with their categories.
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

dolicurateAjaxGuard('read');

$catalog = new DoliCurateCatalog($db);

$filters = array(
	'search' => GETPOST('search', 'alphanohtml'),
	'type' => GETPOSTISSET('type') ? GETPOSTINT('type') : -1,
	'status' => GETPOST('status', 'aZ09'),
	'tagged' => GETPOST('tagged', 'aZ09') ?: 'all',
	'category' => GETPOSTINT('category'),
	'deep' => GETPOSTINT('deep'),
	'supplier' => GETPOSTINT('supplier'),
	'limit' => GETPOSTISSET('limit') ? GETPOSTINT('limit') : getDolGlobalInt('DOLICURATE_PAGE_SIZE', 50),
	'offset' => GETPOSTINT('offset'),
);

$rows = $catalog->listProducts($filters);
$total = $catalog->countProducts($filters);

dolicurateJson(array(
	'ok' => true,
	'rows' => $rows,
	'total' => $total,
	'offset' => (int) $filters['offset'],
	'limit' => (int) $filters['limit'],
));
