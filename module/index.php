<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    index.php
 * \ingroup dolicurate
 * \brief   Coverage dashboard: how much of the catalogue is organised.
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

$catalog = new DoliCurateCatalog($db);
$cov = $catalog->getCoverage();

llxHeader('', $langs->trans('CurateDashboard'), '', '', 0, 0, '', '', '', 'mod-dolicurate page-dashboard');

print dolicurateStylesheetTag();

print load_fiche_titre($langs->trans('CurateDashboard'), '', 'category');

$head = dolicuratePrepareHead('dashboard');
print dol_get_fiche_head($head, 'dashboard', '', -1, '');

// Headline coverage bar.
$pct = (float) $cov['pct'];
print '<div class="dc-cover">';
print '<div class="dc-cover-head">';
print '<span class="dc-cover-title">'.$langs->trans('CoverageTitle').'</span>';
print '<span class="dc-cover-pct">'.$pct.'%</span>';
print '</div>';
print '<div class="dc-bar"><div class="dc-bar-fill" style="width:'.$pct.'%"></div></div>';
print '<div class="dc-cover-sub">';
print '<span>'.$langs->trans('CoverageTagged').': <strong>'.$cov['tagged'].'</strong></span>';
print '<span>'.$langs->trans('CoverageUntagged').': <strong class="'.($cov['untagged'] > 0 ? 'dc-warn' : '').'">'.$cov['untagged'].'</strong></span>';
print '<span>'.$langs->trans('CoverageTotal').': <strong>'.$cov['total'].'</strong></span>';
print '</div>';
print '</div>';

if ($cov['untagged'] > 0) {
	print '<div class="dc-hint">'.$langs->trans('CoverageHint').'</div>';
}

// Stat tiles.
$tiles = array(
	array('CoverageProducts', $cov['products_total'], $cov['products_untagged']),
	array('CoverageServices', $cov['services_total'], $cov['services_untagged']),
);

print '<div class="dc-tiles">';
foreach ($tiles as $t) {
	print '<div class="dc-tile">';
	print '<div class="dc-tile-label">'.$langs->trans($t[0]).'</div>';
	print '<div class="dc-tile-value">'.$t[1].'</div>';
	print '<div class="dc-tile-sub">'.$t[2].' '.$langs->trans('CoverageUntagged').'</div>';
	print '</div>';
}
print '<div class="dc-tile"><div class="dc-tile-label">'.$langs->trans('CoverageCategories').'</div>';
print '<div class="dc-tile-value">'.$cov['categories'].'</div>';
print '<div class="dc-tile-sub">'.$cov['categories_empty'].' '.$langs->trans('CoverageEmptyCategories').'</div></div>';
print '<div class="dc-tile"><div class="dc-tile-label">'.$langs->trans('CoverageLinks').'</div>';
print '<div class="dc-tile-value">'.$cov['links'].'</div>';
print '<div class="dc-tile-sub">&nbsp;</div></div>';
print '</div>';

print '<div class="dc-actions-row">';
print '<a class="button" href="'.dol_buildpath('/dolicurate/assign.php', 1).'?tagged=untagged">'.$langs->trans('ViewUntagged').'</a> ';
print '<a class="button button-cancel" href="'.dol_buildpath('/dolicurate/assign.php', 1).'">'.$langs->trans('StartAssigning').'</a>';
print '</div>';

// Per-category breakdown.
$tree = $catalog->getCategoryTree();
print '<div class="dc-section-title">'.$langs->trans('CoverageCategories').'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CategoryLabel').'</td><td class="right">'.$langs->trans('DirectProducts').'</td></tr>';
if (empty($tree)) {
	print '<tr class="oddeven"><td colspan="2" class="opacitymedium">'.$langs->trans('TreeEmpty').'</td></tr>';
}
foreach ($tree as $node) {
	print '<tr class="oddeven">';
	print '<td style="padding-left:'.(8 + $node['depth'] * 22).'px">';
	if ($node['color']) {
		print '<span class="dc-swatch" style="background:#'.dol_escape_htmltag($node['color']).'"></span>';
	}
	print dol_escape_htmltag($node['label']);
	print '</td>';
	print '<td class="right'.($node['count_direct'] == 0 ? ' opacitymedium' : '').'">'.$node['count_direct'].'</td>';
	print '</tr>';
}
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
