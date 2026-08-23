<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    assign.php
 * \ingroup dolicurate
 * \brief   Bulk assign screen: filter a worklist, select rows, apply categories.
 *
 * The table body and the category picker are rendered client side from
 * ajax/products.php so filtering never costs a page reload.
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
$tree = $catalog->getCategoryTree();

llxHeader('', $langs->trans('AssignTitle'), '', '', 0, 0, '', '', '', 'mod-dolicurate page-assign');

print load_fiche_titre($langs->trans('AssignTitle'), '', 'category');

$head = dolicuratePrepareHead('assign');
print dol_get_fiche_head($head, 'assign', '', -1, '');

// Filter bar.
print '<div class="dc-filters">';
print '<input type="search" id="dc-search" class="dc-input" placeholder="'.dol_escape_htmltag($langs->trans('FilterSearch')).'">';

print '<select id="dc-tagged" class="dc-input">';
$taggedSel = GETPOST('tagged', 'aZ09');
print '<option value="all">'.$langs->trans('FilterAll').'</option>';
print '<option value="untagged"'.($taggedSel === 'untagged' ? ' selected' : '').'>'.$langs->trans('FilterUntagged').'</option>';
print '<option value="tagged"'.($taggedSel === 'tagged' ? ' selected' : '').'>'.$langs->trans('FilterTagged').'</option>';
print '</select>';

print '<select id="dc-type" class="dc-input">';
print '<option value="-1">'.$langs->trans('FilterType').': '.$langs->trans('FilterAll').'</option>';
print '<option value="0">'.$langs->trans('CoverageProducts').'</option>';
print '<option value="1">'.$langs->trans('CoverageServices').'</option>';
print '</select>';

print '<select id="dc-status" class="dc-input">';
print '<option value="">'.$langs->trans('FilterStatus').': '.$langs->trans('FilterAll').'</option>';
print '<option value="sell">'.$langs->trans('FilterForSale').'</option>';
print '<option value="buy">'.$langs->trans('FilterForPurchase').'</option>';
print '</select>';

print '<select id="dc-category" class="dc-input">';
print '<option value="0">'.$langs->trans('FilterCategory').': '.$langs->trans('FilterAll').'</option>';
foreach ($tree as $node) {
	// Indent with raw entities: escaping them along with the label would print them literally.
	print '<option value="'.$node['id'].'">'.str_repeat('&nbsp;&nbsp;', $node['depth']).dol_escape_htmltag($node['label']).'</option>';
}
print '</select>';

print '<label class="dc-check"><input type="checkbox" id="dc-deep" checked> '.$langs->trans('IncludeSubcategories').'</label>';
print '</div>';

// Worklist.
print '<div id="dc-worklist" class="dc-worklist"></div>';
print '<div id="dc-pager" class="dc-pager"></div>';

// Action bar, only rendered for users who may actually write.
if ($user->hasRight('dolicurate', 'curate', 'assign')) {
	print '<div class="dc-actionbar" id="dc-actionbar">';
	print '<div class="dc-selinfo" id="dc-selinfo"></div>';
	print '<div class="dc-catpick">';
	print '<select id="dc-target" class="dc-input" multiple size="4">';
	foreach ($tree as $node) {
		// Indent with raw entities: escaping them along with the label would print them literally.
	print '<option value="'.$node['id'].'">'.str_repeat('&nbsp;&nbsp;', $node['depth']).dol_escape_htmltag($node['label']).'</option>';
	}
	print '</select>';
	print '</div>';
	print '<div class="dc-actbtns">';
	print '<button type="button" class="button" id="dc-add" disabled>'.$langs->trans('AddToCategories').'</button>';
	print '<button type="button" class="button button-cancel" id="dc-remove" disabled>'.$langs->trans('RemoveFromCategories').'</button>';
	print '</div>';
	print '</div>';
}

print dol_get_fiche_end();

print dolicurateConfigBlock('assign');
print '<script src="'.dol_buildpath('/dolicurate/js/dolicurate-assign.js', 1).'?v='.urlencode(getDolGlobalString('MAIN_MODULE_DOLICURATE_VERSION', '1.0.0')).'"></script>';

llxFooter();
$db->close();
