<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    history.php
 * \ingroup dolicurate
 * \brief   Audit trail of applied batches, with undo.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

dol_include_once('/dolicurate/lib/dolicurate.lib.php');
dol_include_once('/dolicurate/class/dolicuratecatalog.class.php');

$langs->loadLangs(array('dolicurate@dolicurate', 'products', 'categories', 'admin'));

if (!isModEnabled('dolicurate')) {
	accessforbidden();
}
if (!$user->hasRight('dolicurate', 'curate', 'read')) {
	accessforbidden();
}

llxHeader('', $langs->trans('HistoryTitle'), '', '', 0, 0, '', '', '', 'mod-dolicurate page-history');

print load_fiche_titre($langs->trans('HistoryTitle'), '', 'category');

$head = dolicuratePrepareHead('history');
print dol_get_fiche_head($head, 'history', '', -1, '');

print '<div id="dc-history"></div>';

print dol_get_fiche_end();

print dolicurateConfigBlock('history');
print '<script src="'.dol_buildpath('/dolicurate/js/dolicurate-history.js', 1).'?v='.urlencode(dolicurateAssetVersion('/dolicurate/js/dolicurate-history.js')).'"></script>';

llxFooter();
$db->close();
