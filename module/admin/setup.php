<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    admin/setup.php
 * \ingroup dolicurate
 * \brief   Administration page for Doli Curate.
 */

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
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/dolicurate/lib/dolicurate.lib.php');

$langs->loadLangs(array('admin', 'products', 'dolicurate@dolicurate'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Only the non-boolean settings post here; toggles save themselves over AJAX.
if ($action === 'update') {
	$constname = GETPOST('constname', 'alpha');
	$constvalue = GETPOST('constvalue', 'alphanohtml');

	$bounds = array(
		'DOLICURATE_PAGE_SIZE' => array(5, 500),
		'DOLICURATE_PREVIEW_LIMIT' => array(10, 2000),
		'DOLICURATE_BATCH_LIMIT' => array(1, 20000),
		'DOLICURATE_KEEP_LOG_DAYS' => array(1, 3650),
	);

	if (!isset($bounds[$constname])) {
		setEventMessages($langs->trans('ErrorBadParameters'), null, 'errors');
	} else {
		list($min, $max) = $bounds[$constname];
		$constvalue = (string) max($min, min($max, (int) $constvalue));

		if (dolibarr_set_const($db, $constname, $constvalue, 'chaine', 0, '', $conf->entity) > 0) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}

	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

llxHeader('', $langs->trans('DoliCurateSetup'), '', '', 0, 0, '', '', '', 'mod-dolicurate page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DoliCurateSetup'), $linkback, 'title_setup');

print '<span class="opacitymedium">'.$langs->trans('DoliCurateSetupIntro').'</span><br><br>';

if (!isModEnabled('categorie')) {
	print info_admin($langs->trans('WarningModuleNotActive', $langs->transnoentities('Categories')), 0, 0, 'warning');
}
if (!isModEnabled('product') && !isModEnabled('service')) {
	print info_admin($langs->trans('WarningModuleNotActive', $langs->transnoentities('Products')), 0, 0, 'warning');
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Parameters').'</td>';
print '<td class="center" width="140">'.$langs->trans('Value').'</td>';
print '<td>'.$langs->trans('Description').'</td>';
print '</tr>';

dolicuratePrintInputRow('DOLICURATE_PAGE_SIZE', 'PageSize', 'PageSizeDesc', 'number', '50', 'min="5" max="500" style="width:80px;"');
dolicuratePrintInputRow('DOLICURATE_PREVIEW_LIMIT', 'PreviewLimit', 'PreviewLimitDesc', 'number', '200', 'min="10" max="2000" style="width:80px;"');
dolicuratePrintInputRow('DOLICURATE_BATCH_LIMIT', 'BatchLimit', 'BatchLimitDesc', 'number', '2000', 'min="1" max="20000" style="width:80px;"');
dolicuratePrintInputRow('DOLICURATE_KEEP_LOG_DAYS', 'KeepLogDays', 'KeepLogDaysDesc', 'number', '90', 'min="1" max="3650" style="width:80px;"');
dolicuratePrintToggleRow('DOLICURATE_SHOW_IMAGES', 'ShowImages', 'ShowImagesDesc');
dolicuratePrintToggleRow('DOLICURATE_DEBUG_MODE', 'DebugMode', 'DebugModeDesc');

print '</table>';
print '</div>';

llxFooter();
$db->close();
