<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works> */

/**
 * \file    ajax/debug.php
 * \ingroup dolicurate
 * \brief   Diagnostics. Admin only, and only when DOLICURATE_DEBUG_MODE is on.
 *
 * Modes: overview | tree | coverage | rules | history | sql | all
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
	http_response_code(500);
	exit;
}

if (empty($user->admin)) {
	http_response_code(403);
	print 'Admin only';
	exit;
}
if (!getDolGlobalInt('DOLICURATE_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Home > Setup > Modules > Doli Curate > Setup.';
	exit;
}

header('Content-Type: text/plain; charset=utf-8');

dol_include_once('/dolicurate/class/dolicuratecatalog.class.php');
dol_include_once('/dolicurate/class/dolicuratecurator.class.php');
dol_include_once('/dolicurate/class/dolicuraterules.class.php');
dol_include_once('/dolicurate/class/dolicuratetree.class.php');

$mode = GETPOST('mode', 'alpha') ?: 'overview';
$all = ($mode === 'all');

$TABLES = array('dolicurate_ruleset', 'dolicurate_rule', 'dolicurate_log');

print "=== DOLICURATE DEBUG ===\n";
print "Dolibarr: ".(defined('DOL_VERSION') ? DOL_VERSION : '?')."\n";
print "Module version: ".getDolGlobalString('MAIN_MODULE_DOLICURATE_VERSION', 'unknown')."\n";
print "Entity: ".((int) $conf->entity)."  User: ".$user->login."\n";
print "Mode: ".$mode."   (overview|tree|coverage|rules|history|sql|all)\n";
print str_repeat('=', 62)."\n\n";

if ($mode === 'overview' || $all) {
	print "--- STATUS ---\n";
	foreach (array('dolicurate', 'product', 'service', 'categorie') as $m) {
		printf("  %-12s %s\n", $m, isModEnabled($m) ? 'ENABLED' : 'off');
	}

	print "\n--- RIGHTS (current user) ---\n";
	foreach (array('read', 'assign', 'rules', 'tree', 'undo') as $r) {
		printf("  curate->%-7s %s\n", $r, $user->hasRight('dolicurate', 'curate', $r) ? 'YES' : 'no');
	}

	print "\n--- TABLES ---\n";
	foreach ($TABLES as $t) {
		$resql = $db->query("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX.$t);
		if ($resql && ($o = $db->fetch_object($resql))) {
			printf("  llx_%-24s %d rows\n", $t, $o->c);
		} else {
			printf("  llx_%-24s MISSING\n", $t);
		}
	}

	print "\n--- ASSETS ---\n";
	foreach (array('/dolicurate/css/dolicurate.css', '/dolicurate/js/dolicurate-core.js',
		'/dolicurate/js/dolicurate-assign.js', '/dolicurate/js/dolicurate-rules.js',
		'/dolicurate/js/dolicurate-tree.js', '/dolicurate/js/dolicurate-history.js') as $a) {
		printf("  %-42s %s\n", $a, file_exists(dol_buildpath($a)) ? 'ok' : 'MISSING');
	}
	print "\n";
}

if ($mode === 'coverage' || $all) {
	$catalog = new DoliCurateCatalog($db);
	$c = $catalog->getCoverage();
	print "--- COVERAGE ---\n";
	foreach ($c as $k => $v) {
		printf("  %-20s %s\n", $k, $v);
	}
	print "\n";
}

if ($mode === 'tree' || $all) {
	$catalog = new DoliCurateCatalog($db);
	$tree = $catalog->getCategoryTree();
	print "--- CATEGORY TREE (".count($tree)." nodes) ---\n";
	foreach ($tree as $n) {
		printf("  %s[%d] %-36s direct=%d\n", str_repeat('  ', $n['depth']), $n['id'], $n['label'], $n['count_direct']);
	}
	print "\n";
}

if ($mode === 'rules' || $all) {
	$rules = new DoliCurateRules($db);
	print "--- RULE SETS ---\n";
	foreach ($rules->listRuleSets() as $s) {
		printf("  [%d] %-28s rules=%d only_untagged=%d\n", $s['id'], $s['label'], $s['rulecount'], $s['only_untagged']);
		foreach ($rules->listRules($s['id']) as $r) {
			printf("        type=%d value=%-18s -> cat %d (%s)\n", $r['match_type'], $r['match_value'], $r['category'], $r['category_label']);
		}
	}
	print "\n";
}

if ($mode === 'history' || $all) {
	$curator = new DoliCurateCurator($db);
	print "--- RECENT BATCHES ---\n";
	foreach ($curator->listBatches(25) as $b) {
		printf("  %s src=%d by=%-10s +%-4d -%-4d %s\n",
			dol_print_date($b['started'], 'dayhoursec'), $b['source'], $b['user'],
			$b['adds'], $b['removes'], $b['undone'] ? '(undone)' : '');
		printf("        batch=%s\n", $b['batch']);
	}
	print "\n";
}

if ($mode === 'sql') {
	$q = trim((string) GETPOST('q', 'restricthtml'));
	print "--- SQL ---\n";
	if ($q === '') {
		print "Usage: ?mode=sql&q=SELECT+...\n\n";
		print "  ?mode=sql&q=SELECT * FROM llx_dolicurate_log ORDER BY rowid DESC LIMIT 20\n";
		print "  ?mode=sql&q=SELECT batch_id,COUNT(*) FROM llx_dolicurate_log GROUP BY batch_id\n";
	} elseif (stripos($q, 'SELECT') !== 0) {
		print "ERROR: only SELECT is allowed.\n";
	} elseif (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE)\b/i', $q)) {
		print "ERROR: blocked keyword.\n";
	} else {
		if (stripos($q, 'LIMIT') === false) {
			$q .= ' LIMIT 50';
		}
		print "Query: ".$q."\n\n";
		$resql = $db->query($q);
		if (!$resql) {
			print "SQL ERROR: ".$db->lasterror()."\n";
		} else {
			$first = true;
			$n = 0;
			while ($row = $db->fetch_array($resql)) {
				$named = array();
				foreach ($row as $k => $v) {
					if (!is_int($k)) {
						$named[$k] = $v;
					}
				}
				if ($first) {
					print implode("\t", array_keys($named))."\n".str_repeat('-', 76)."\n";
					$first = false;
				}
				$n++;
				print implode("\t", array_map(function ($v) {
					return $v === null ? 'NULL' : (strlen((string) $v) > 38 ? substr((string) $v, 0, 38).'...' : $v);
				}, $named))."\n";
			}
			print "\n".$n." rows.\n";
		}
	}
	print "\n";
}

print "=== END ===\n";
