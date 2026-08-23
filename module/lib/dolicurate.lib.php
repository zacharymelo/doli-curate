<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/dolicurate.lib.php
 * \ingroup dolicurate
 * \brief   Shared helpers: page tabs, JSON replies, admin form rows.
 */

/**
 * Tabs across the curation screens.
 *
 * @param  string $active Active tab code
 * @return array<int,array<int,string>> Head array for dol_get_fiche_head()
 */
function dolicuratePrepareHead($active = 'dashboard')
{
	global $langs, $conf, $user;

	$langs->load('dolicurate@dolicurate');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/dolicurate/index.php', 1);
	$head[$h][1] = $langs->trans('Dashboard');
	$head[$h][2] = 'dashboard';
	$h++;

	$head[$h][0] = dol_buildpath('/dolicurate/assign.php', 1);
	$head[$h][1] = $langs->trans('MenuAssign');
	$head[$h][2] = 'assign';
	$h++;

	if ($user->hasRight('dolicurate', 'curate', 'rules')) {
		$head[$h][0] = dol_buildpath('/dolicurate/rules.php', 1);
		$head[$h][1] = $langs->trans('MenuRules');
		$head[$h][2] = 'rules';
		$h++;
	}

	if ($user->hasRight('dolicurate', 'curate', 'tree')) {
		$head[$h][0] = dol_buildpath('/dolicurate/tree.php', 1);
		$head[$h][1] = $langs->trans('MenuTree');
		$head[$h][2] = 'tree';
		$h++;
	}

	$head[$h][0] = dol_buildpath('/dolicurate/history.php', 1);
	$head[$h][1] = $langs->trans('MenuHistory');
	$head[$h][2] = 'history';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolicurate@dolicurate');

	return $head;
}

/**
 * Emit a JSON payload and stop.
 *
 * @param  array<string,mixed> $payload Response body
 * @param  int                 $status  HTTP status code
 * @return never
 */
function dolicurateJson($payload, $status = 200)
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	print json_encode($payload);
	exit;
}

/**
 * Shared guard for the module's AJAX endpoints.
 *
 * @param  string $right   Right under the 'curate' object, e.g. 'assign'
 * @param  bool   $needPost Require a POST request
 * @return void
 */
function dolicurateAjaxGuard($right = 'read', $needPost = false)
{
	global $user;

	if ($needPost && $_SERVER['REQUEST_METHOD'] !== 'POST') {
		dolicurateJson(array('ok' => false, 'error' => 'MethodNotAllowed'), 405);
	}
	if (!isModEnabled('dolicurate')) {
		dolicurateJson(array('ok' => false, 'error' => 'ModuleDisabled'), 403);
	}
	if (empty($user->id)) {
		dolicurateJson(array('ok' => false, 'error' => 'NotAuthenticated'), 401);
	}
	if (!$user->hasRight('dolicurate', 'curate', $right)) {
		dolicurateJson(array('ok' => false, 'error' => 'AccessDenied'), 403);
	}
	// Curating means editing products; never exceed what Dolibarr already allows.
	if ($right !== 'read' && !$user->hasRight('produit', 'creer') && !$user->hasRight('service', 'creer')) {
		dolicurateJson(array('ok' => false, 'error' => 'AccessDenied'), 403);
	}
}

/**
 * Render one on/off setting row using Dolibarr's standard AJAX toggle.
 *
 * @param  string $constant Constant name
 * @param  string $labelKey Translation key for the label
 * @param  string $helpKey  Translation key for the help text
 * @return void
 */
function dolicuratePrintToggleRow($constant, $labelKey, $helpKey)
{
	global $langs;

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans($labelKey).'</td>';
	print '<td class="center">'.ajax_constantonoff($constant).'</td>';
	print '<td class="opacitymedium">'.$langs->trans($helpKey).'</td>';
	print '</tr>';
}

/**
 * Render one numeric or free-text setting row with its own inline form.
 *
 * @param  string $constant Constant name
 * @param  string $labelKey Translation key for the label
 * @param  string $helpKey  Translation key for the help text
 * @param  string $type     HTML input type
 * @param  string $default  Value used when the constant is unset
 * @param  string $extra    Extra HTML attributes
 * @return void
 */
function dolicuratePrintInputRow($constant, $labelKey, $helpKey, $type = 'text', $default = '', $extra = '')
{
	global $langs;

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans($labelKey).'</td>';
	print '<td class="center">';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="constname" value="'.dol_escape_htmltag($constant).'">';
	print '<input type="'.dol_escape_htmltag($type).'" name="constvalue" '.$extra;
	print ' value="'.dol_escape_htmltag(getDolGlobalString($constant, $default)).'">';
	print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
	print '</form>';
	print '</td>';
	print '<td class="opacitymedium">'.$langs->trans($helpKey).'</td>';
	print '</tr>';
}

/**
 * Bootstrap payload shared by every screen's JavaScript.
 *
 * @param  string $screen Screen name
 * @return string         A <script type="application/json"> block
 */
function dolicurateConfigBlock($screen)
{
	global $conf, $user;

	$config = array(
		'screen' => $screen,
		'token' => newToken(),
		'urlProducts' => dol_buildpath('/dolicurate/ajax/products.php', 1),
		'urlAssign' => dol_buildpath('/dolicurate/ajax/assign.php', 1),
		'urlRules' => dol_buildpath('/dolicurate/ajax/rules.php', 1),
		'urlTree' => dol_buildpath('/dolicurate/ajax/tree.php', 1),
		'urlStats' => dol_buildpath('/dolicurate/ajax/stats.php', 1),
		'pageSize' => max(1, getDolGlobalInt('DOLICURATE_PAGE_SIZE', 50)),
		'previewLimit' => max(1, getDolGlobalInt('DOLICURATE_PREVIEW_LIMIT', 200)),
		'can' => array(
			'assign' => $user->hasRight('dolicurate', 'curate', 'assign') ? 1 : 0,
			'rules' => $user->hasRight('dolicurate', 'curate', 'rules') ? 1 : 0,
			'tree' => $user->hasRight('dolicurate', 'curate', 'tree') ? 1 : 0,
			'undo' => $user->hasRight('dolicurate', 'curate', 'undo') ? 1 : 0,
		),
	);

	$version = urlencode(getDolGlobalString('MAIN_MODULE_DOLICURATE_VERSION', '1.0.0'));

	$out = '<script type="application/json" id="dolicurate-config">'.json_encode($config).'</script>';
	// Shared helpers, loaded here so every screen gets them from one place.
	$out .= '<script src="'.dol_buildpath('/dolicurate/js/dolicurate-core.js', 1).'?v='.$version.'"></script>';

	return $out;
}
