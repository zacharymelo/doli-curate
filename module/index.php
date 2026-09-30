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

// A parent missing from the walked tree (orphaned or beyond the depth cap)
// makes the node a root, matching how getCategoryTree() places it.
$inTree = array();
foreach ($tree as $node) {
	$inTree[$node['id']] = true;
}
$childCount = array();
foreach ($tree as $node) {
	$p = $node['parent'];
	if ($p > 0 && isset($inTree[$p])) {
		$childCount[$p] = (isset($childCount[$p]) ? $childCount[$p] : 0) + 1;
	}
}

// The user's expanded/collapsed branches are remembered in a cookie that the
// dashboard script writes. Read it here so the page renders in that state
// without a flash; with no cookie the admin's default applies.
$expandDefault = getDolGlobalInt('DOLICURATE_EXPAND_LISTS') ? 1 : 0;
$listState = dolicurateReadListCookie('dolicurate_dash_open');
$open = array();
foreach ($childCount as $cid => $unused) {
	if ($listState['mode'] === 'open') {
		$open[$cid] = isset($listState['ids'][$cid]);
	} elseif ($listState['mode'] === 'closed') {
		$open[$cid] = !isset($listState['ids'][$cid]);
	} else {
		$open[$cid] = (bool) $expandDefault;
	}
}

print '<div class="dc-section-title">'.$langs->trans('CoverageCategories').'</div>';
if (!empty($childCount)) {
	print '<div class="dc-actions-row">';
	print '<a href="#" class="dc-tree-all" data-open="1">'.$langs->trans('DashExpandAll').'</a> &middot; ';
	print '<a href="#" class="dc-tree-all" data-open="0">'.$langs->trans('DashCollapseAll').'</a>';
	print '</div>';
}
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" id="dc-dash-tree" data-cookie-path="'.dol_escape_htmltag(dol_buildpath('/dolicurate/', 1)).'" data-expand-default="'.$expandDefault.'">';
print '<tr class="liste_titre"><td>'.$langs->trans('CategoryLabel').'</td><td class="right">'.$langs->trans('DirectProducts').'</td></tr>';
if (empty($tree)) {
	print '<tr class="oddeven"><td colspan="2" class="opacitymedium">'.$langs->trans('TreeEmpty').'</td></tr>';
}
$shown = array();
foreach ($tree as $node) {
	$id = $node['id'];
	$parent = ($node['parent'] > 0 && isset($inTree[$node['parent']])) ? $node['parent'] : 0;
	// The tree is walked depth-first, so a parent's visibility is already known.
	$shown[$id] = $parent === 0 || (!empty($shown[$parent]) && !empty($open[$parent]));
	$kids = isset($childCount[$id]) ? $childCount[$id] : 0;

	print '<tr class="oddeven'.($shown[$id] ? '' : ' dc-hidden').'" data-id="'.$id.'" data-parent="'.$parent.'">';
	print '<td style="padding-left:'.(8 + $node['depth'] * 22).'px">';
	if ($kids > 0) {
		$isOpen = !empty($open[$id]);
		print '<button type="button" class="dc-caret dc-tree-toggle" aria-expanded="'.($isOpen ? 'true' : 'false').'" title="'.dol_escape_htmltag($langs->trans('DashToggleCategory')).'">'.($isOpen ? '▾' : '▸').'</button> ';
	} else {
		print '<span class="dc-caret-spacer"></span> ';
	}
	if ($node['color']) {
		print '<span class="dc-swatch" style="background:#'.dol_escape_htmltag($node['color']).'"></span>';
	}
	print dol_escape_htmltag($node['label']);
	if ($kids > 0) {
		print ' <span class="opacitymedium small">('.($kids === 1 ? $langs->trans('DashSubcategory') : $langs->trans('DashSubcategories', $kids)).')</span>';
	}
	print '</td>';
	print '<td class="right'.($node['count_direct'] == 0 ? ' opacitymedium' : '').'">'.$node['count_direct'].'</td>';
	print '</tr>';
}
print '</table></div>';

print '<script src="'.dol_buildpath('/dolicurate/js/dolicurate-dashboard.js', 1).'?v='.urlencode(dolicurateAssetVersion('/dolicurate/js/dolicurate-dashboard.js')).'"></script>';

print dol_get_fiche_end();

llxFooter();
$db->close();
