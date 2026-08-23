<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    tree.php
 * \ingroup dolicurate
 * \brief   Category tree management: create, rename, move, merge, delete.
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

if (!$user->hasRight('dolicurate', 'curate', 'tree')) {
	accessforbidden();
}

llxHeader('', $langs->trans('TreeTitle'), '', '', 0, 0, '', '', '', 'mod-dolicurate page-tree');

print load_fiche_titre($langs->trans('TreeTitle'), '', 'category');

$head = dolicuratePrepareHead('tree');
print dol_get_fiche_head($head, 'tree', '', -1, '');

print '<div class="dc-newcat">';
print '<input type="text" id="dc-cat-label" class="dc-input" placeholder="'.dol_escape_htmltag($langs->trans('CategoryLabel')).'">';
print '<select id="dc-cat-parent" class="dc-input"></select>';
print '<input type="color" id="dc-cat-color" value="#3b82f6" title="'.dol_escape_htmltag($langs->trans('CategoryColor')).'">';
print '<button type="button" class="button" id="dc-cat-create">'.$langs->trans('NewCategory').'</button>';
print '</div>';

print '<div id="dc-tree" class="dc-tree"></div>';

print dol_get_fiche_end();

print dolicurateConfigBlock('tree');
print '<script src="'.dol_buildpath('/dolicurate/js/dolicurate-tree.js', 1).'?v='.urlencode(getDolGlobalString('MAIN_MODULE_DOLICURATE_VERSION', '1.0.0')).'"></script>';

llxFooter();
$db->close();
