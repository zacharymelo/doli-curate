<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    rules.php
 * \ingroup dolicurate
 * \brief   Rule set editor with live preview before anything is written.
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

dol_include_once('/dolicurate/class/dolicuraterules.class.php');

if (!$user->hasRight('dolicurate', 'curate', 'rules')) {
	accessforbidden();
}

$catalog = new DoliCurateCatalog($db);
$tree = $catalog->getCategoryTree();

llxHeader('', $langs->trans('RulesTitle'), '', '', 0, 0, '', '', '', 'mod-dolicurate page-rules');

print load_fiche_titre($langs->trans('RulesTitle'), '', 'category');

$head = dolicuratePrepareHead('rules');
print dol_get_fiche_head($head, 'rules', '', -1, '');

print '<div class="dc-rules-layout">';

// Left: rule sets.
print '<div class="dc-rules-list">';
print '<div class="dc-section-title">'.$langs->trans('RuleSets').'</div>';
print '<div id="dc-ruleset-list"></div>';
print '<div class="dc-newset">';
print '<input type="text" id="dc-newset-label" class="dc-input" placeholder="'.dol_escape_htmltag($langs->trans('RuleSetLabel')).'">';
print '<label class="dc-check"><input type="checkbox" id="dc-newset-untagged" checked> '.$langs->trans('RuleSetOnlyUntagged').'</label>';
print '<button type="button" class="button" id="dc-newset-go">'.$langs->trans('NewRuleSet').'</button>';
print '</div>';
print '</div>';

// Right: the selected set, its rules, and the preview.
print '<div class="dc-rules-detail" id="dc-ruleset-detail">';
print '<div class="dc-empty">'.$langs->trans('NoRuleSets').'</div>';
print '</div>';

print '</div>';

// Category options reused by the rule editor, rendered once.
print '<select id="dc-cat-template" class="hidden" style="display:none">';
foreach ($tree as $node) {
	print '<option value="'.$node['id'].'">'.str_repeat('&nbsp;&nbsp;', $node['depth']).dol_escape_htmltag($node['label']).'</option>';
}
print '</select>';

print dol_get_fiche_end();

print dolicurateConfigBlock('rules');
print '<script src="'.dol_buildpath('/dolicurate/js/dolicurate-rules.js', 1).'?v='.urlencode(getDolGlobalString('MAIN_MODULE_DOLICURATE_VERSION', '1.0.0')).'"></script>';

llxFooter();
$db->close();
