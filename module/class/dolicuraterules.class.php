<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/dolicuraterules.class.php
 * \ingroup dolicurate
 * \brief   Saved tagging rule sets: storage, preview and application.
 *
 * A rule set is re-runnable: it can be applied again as new products arrive.
 * Matching is done in SQL so a preview over a large catalogue stays cheap.
 */

dol_include_once('/dolicurate/class/dolicuratecurator.class.php');

/**
 * Rule sets that map product attributes onto categories.
 */
class DoliCurateRules
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/** Ref begins with the value */
	const MATCH_PREFIX = 1;

	/** Ref ends with the value */
	const MATCH_SUFFIX = 2;

	/** Ref matches the value as a regular expression */
	const MATCH_REGEX = 3;

	/** Label contains the value */
	const MATCH_LABEL = 4;

	/** Product type equals the value (0 product, 1 service) */
	const MATCH_TYPE = 5;

	/** Product has a purchase price for the supplier id in the value */
	const MATCH_SUPPLIER = 6;

	/** Matches every product */
	const MATCH_ALL = 7;

	/** Ref equals the value exactly */
	const MATCH_REF = 8;

	/**
	 * Human-readable names for the match types, for UI and validation.
	 *
	 * @return array<int,string> Match type => label key
	 */
	public static function matchTypes()
	{
		return array(
			self::MATCH_PREFIX => 'MatchPrefix',
			self::MATCH_SUFFIX => 'MatchSuffix',
			self::MATCH_REGEX => 'MatchRegex',
			self::MATCH_LABEL => 'MatchLabel',
			self::MATCH_TYPE => 'MatchType',
			self::MATCH_SUPPLIER => 'MatchSupplier',
			self::MATCH_ALL => 'MatchAll',
			self::MATCH_REF => 'MatchRef',
		);
	}

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------ rule sets

	/**
	 * All rule sets with their rule counts.
	 *
	 * @return array<int,array<string,mixed>> Rule sets
	 */
	public function listRuleSets()
	{
		$sql = "SELECT s.rowid, s.label, s.description, s.only_untagged, s.active,";
		$sql .= " s.date_creation, s.date_lastrun,";
		$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."dolicurate_rule r WHERE r.fk_ruleset = s.rowid) as rulecount";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolicurate_ruleset as s";
		$sql .= " WHERE s.entity IN (".getEntity('dolicurate').")";
		$sql .= " ORDER BY s.label ASC";

		$out = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}
		while ($o = $this->db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $o->rowid,
				'label' => (string) $o->label,
				'description' => (string) $o->description,
				'only_untagged' => (int) $o->only_untagged,
				'active' => (int) $o->active,
				'rulecount' => (int) $o->rulecount,
				'date_creation' => $this->db->jdate($o->date_creation),
				'date_lastrun' => $o->date_lastrun ? $this->db->jdate($o->date_lastrun) : 0,
			);
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Create a rule set.
	 *
	 * @param  string $label        Unique label
	 * @param  string $description  Free text
	 * @param  int    $onlyUntagged Restrict runs to untagged products
	 * @param  User   $user         Acting user
	 * @return int                  New id, or -1 on error
	 */
	public function createRuleSet($label, $description, $onlyUntagged, $user)
	{
		global $conf;

		$label = trim($label);
		if ($label === '') {
			$this->error = 'LabelRequired';
			return -1;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."dolicurate_ruleset";
		$sql .= " (entity, label, description, only_untagged, active, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity);
		$sql .= ", '".$this->db->escape($label)."'";
		$sql .= ", '".$this->db->escape($description)."'";
		$sql .= ", ".($onlyUntagged ? 1 : 0);
		$sql .= ", 1";
		$sql .= ", '".$this->db->idate(dol_now())."'";
		$sql .= ", ".((int) $user->id).")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX."dolicurate_ruleset");
	}

	/**
	 * Delete a rule set and its rules. Audit history is retained.
	 *
	 * @param  int $id Rule set id
	 * @return int     1 on success, -1 on error
	 */
	public function deleteRuleSet($id)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return -1;
		}

		$this->db->begin();

		$ok = $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."dolicurate_rule WHERE fk_ruleset = ".$id);
		$ok = $ok && $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."dolicurate_ruleset WHERE rowid = ".$id);

		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		return 1;
	}

	/**
	 * Fetch one rule set with its rules.
	 *
	 * @param  int                      $id Rule set id
	 * @return array<string,mixed>|null     Rule set, or null when missing
	 */
	public function getRuleSet($id)
	{
		$id = (int) $id;

		$sql = "SELECT rowid, label, description, only_untagged, active FROM ".MAIN_DB_PREFIX."dolicurate_ruleset";
		$sql .= " WHERE rowid = ".$id." AND entity IN (".getEntity('dolicurate').")";

		$resql = $this->db->query($sql);
		$o = $resql ? $this->db->fetch_object($resql) : null;
		if (!$o) {
			return null;
		}
		$this->db->free($resql);

		return array(
			'id' => (int) $o->rowid,
			'label' => (string) $o->label,
			'description' => (string) $o->description,
			'only_untagged' => (int) $o->only_untagged,
			'active' => (int) $o->active,
			'rules' => $this->listRules($id),
		);
	}

	/**
	 * Rules belonging to a rule set, in evaluation order.
	 *
	 * @param  int $rulesetId Rule set id
	 * @return array<int,array<string,mixed>> Rules
	 */
	public function listRules($rulesetId)
	{
		$sql = "SELECT r.rowid, r.match_type, r.match_value, r.fk_categorie, r.rang, c.label as catlabel";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolicurate_rule as r";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = r.fk_categorie";
		$sql .= " WHERE r.fk_ruleset = ".((int) $rulesetId);
		$sql .= " ORDER BY r.rang ASC, r.rowid ASC";

		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$out[] = array(
					'id' => (int) $o->rowid,
					'match_type' => (int) $o->match_type,
					'match_value' => (string) $o->match_value,
					'value_label' => (string) $o->match_value,
					'category' => (int) $o->fk_categorie,
					'category_label' => (string) $o->catlabel,
					'rang' => (int) $o->rang,
				);
			}
			$this->db->free($resql);
		}

		$this->decorateValueLabels($out);

		return $out;
	}

	/**
	 * Replace raw stored values with something a human can read.
	 *
	 * A supplier rule stores a thirdparty id and a type rule stores 0 or 1;
	 * neither means anything on screen. Supplier names are resolved in one
	 * lookup rather than per row.
	 *
	 * @param  array<int,array<string,mixed>> $rules Rules, by reference
	 * @return void
	 */
	private function decorateValueLabels(&$rules)
	{
		global $langs;

		$supplierIds = array();
		foreach ($rules as $r) {
			if ((int) $r['match_type'] === self::MATCH_SUPPLIER && (int) $r['match_value'] > 0) {
				$supplierIds[(int) $r['match_value']] = true;
			}
		}

		$names = array();
		if (!empty($supplierIds)) {
			$sql = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe";
			$sql .= " WHERE rowid IN (".$this->db->sanitize(implode(',', array_keys($supplierIds))).")";
			$sql .= " AND entity IN (".getEntity('societe').")";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($o = $this->db->fetch_object($resql)) {
					$names[(int) $o->rowid] = (string) $o->nom;
				}
				$this->db->free($resql);
			}
		}

		foreach ($rules as $k => $r) {
			$type = (int) $r['match_type'];
			$value = (string) $r['match_value'];

			if ($type === self::MATCH_SUPPLIER) {
				$id = (int) $value;
				// A supplier deleted after the rule was written must not read as
				// a silently valid rule.
				$rules[$k]['value_label'] = isset($names[$id])
					? $names[$id]
					: '#'.$id.' ('.$langs->transnoentities('Unknown').')';
			} elseif ($type === self::MATCH_TYPE) {
				$rules[$k]['value_label'] = ((int) $value === 1)
					? $langs->transnoentities('Service')
					: $langs->transnoentities('Product');
			} elseif ($type === self::MATCH_ALL) {
				$rules[$k]['value_label'] = '-';
			}
		}
	}

	/**
	 * Add a rule to a rule set.
	 *
	 * @param  int    $rulesetId  Rule set id
	 * @param  int    $matchType  MATCH_* constant
	 * @param  string $matchValue Value for the match
	 * @param  int    $categoryId Category to assign
	 * @return int                New rule id, or -1 on error
	 */
	public function addRule($rulesetId, $matchType, $matchValue, $categoryId)
	{
		global $conf;

		$rulesetId = (int) $rulesetId;
		$matchType = (int) $matchType;
		$categoryId = (int) $categoryId;

		if ($rulesetId <= 0 || $categoryId <= 0 || !array_key_exists($matchType, self::matchTypes())) {
			$this->error = 'BadParameters';
			return -1;
		}

		// A malformed pattern would otherwise fail silently at preview time.
		if ($matchType === self::MATCH_REGEX && @preg_match($this->buildRegex($matchValue), '') === false) {
			$this->error = 'InvalidRegex';
			return -1;
		}

		// Every match type except "all" needs something to match on.
		if ($matchType !== self::MATCH_ALL && trim((string) $matchValue) === '') {
			$this->error = 'MatchValueRequired';
			return -1;
		}

		// A supplier rule stores a thirdparty id; reject anything that is not
		// actually a supplier, so a rule cannot be saved that matches nothing.
		if ($matchType === self::MATCH_SUPPLIER) {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
			$sql .= " WHERE rowid = ".((int) $matchValue);
			$sql .= " AND fournisseur = 1";
			$sql .= " AND entity IN (".getEntity('societe').")";
			$resql = $this->db->query($sql);
			$found = ($resql && $this->db->num_rows($resql) > 0);
			if ($resql) {
				$this->db->free($resql);
			}
			if (!$found) {
				$this->error = 'NotASupplier';
				return -1;
			}
			$matchValue = (string) ((int) $matchValue);
		}

		if ($matchType === self::MATCH_TYPE) {
			if (!in_array((int) $matchValue, array(0, 1), true)) {
				$this->error = 'BadProductType';
				return -1;
			}
			$matchValue = (string) ((int) $matchValue);
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."dolicurate_rule";
		$sql .= " (entity, fk_ruleset, match_type, match_value, fk_categorie, rang, date_creation)";
		$sql .= " VALUES (".((int) $conf->entity);
		$sql .= ", ".$rulesetId;
		$sql .= ", ".$matchType;
		$sql .= ", '".$this->db->escape($matchValue)."'";
		$sql .= ", ".$categoryId;
		$sql .= ", (SELECT COALESCE(MAX(rang), 0) + 1 FROM (SELECT rang FROM ".MAIN_DB_PREFIX."dolicurate_rule WHERE fk_ruleset = ".$rulesetId.") x)";
		$sql .= ", '".$this->db->idate(dol_now())."')";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX."dolicurate_rule");
	}

	/**
	 * Delete a single rule.
	 *
	 * @param  int $ruleId Rule id
	 * @return int         1 on success, -1 on error
	 */
	public function deleteRule($ruleId)
	{
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."dolicurate_rule WHERE rowid = ".((int) $ruleId))) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	// -------------------------------------------------------------- matching

	/**
	 * Wrap a user-supplied pattern as a delimited, case-insensitive regex.
	 *
	 * @param  string $value Raw pattern
	 * @return string        Delimited pattern
	 */
	private function buildRegex($value)
	{
		return '/'.str_replace('/', '\/', (string) $value).'/i';
	}

	/**
	 * SQL predicate selecting the products a rule matches.
	 *
	 * MATCH_REGEX is the one type without a portable SQL equivalent, so it is
	 * evaluated in PHP by the caller; this returns null for it.
	 *
	 * @param  array<string,mixed> $rule Rule row
	 * @return string|null               SQL predicate, or null when not expressible
	 */
	private function ruleSqlPredicate($rule)
	{
		$value = (string) $rule['match_value'];
		$like = $this->db->escape($this->db->escapeforlike($value));

		switch ((int) $rule['match_type']) {
			case self::MATCH_ALL:
				return "1 = 1";

			case self::MATCH_REF:
				return "p.ref = '".$this->db->escape($value)."'";

			case self::MATCH_PREFIX:
				return "p.ref LIKE '".$like."%'";

			case self::MATCH_SUFFIX:
				return "p.ref LIKE '%".$like."'";

			case self::MATCH_LABEL:
				return "p.label LIKE '%".$like."%'";

			case self::MATCH_TYPE:
				return "p.fk_product_type = ".((int) $value);

			case self::MATCH_SUPPLIER:
				return "EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."product_fournisseur_price pfp"
					." WHERE pfp.fk_product = p.rowid AND pfp.fk_soc = ".((int) $value).")";

			case self::MATCH_REGEX:
				return null;
		}

		return null;
	}

	/**
	 * Products a single rule would newly add to its category.
	 *
	 * Products already in the target category are excluded, so a preview shows
	 * only the changes a run would actually make.
	 *
	 * @param  array<string,mixed> $rule         Rule row
	 * @param  bool                $onlyUntagged Restrict to products in no category
	 * @param  int                 $limit        Maximum rows to return
	 * @return array<int,array<string,mixed>>    Matching products
	 */
	public function previewRule($rule, $onlyUntagged, $limit = 200)
	{
		$limit = max(1, min((int) $limit, 2000));
		$predicate = $this->ruleSqlPredicate($rule);
		$isRegex = ((int) $rule['match_type'] === self::MATCH_REGEX);

		$sql = "SELECT p.rowid, p.ref, p.label, p.fk_product_type";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " WHERE p.entity IN (".getEntity('product').")";

		if ($predicate !== null) {
			$sql .= " AND (".$predicate.")";
		}
		if ($onlyUntagged) {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cp WHERE cp.fk_product = p.rowid)";
		}
		// Exclude what is already in the target category.
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cpt";
		$sql .= " WHERE cpt.fk_product = p.rowid AND cpt.fk_categorie = ".((int) $rule['category']).")";
		$sql .= " ORDER BY p.ref ASC";
		// A regex is filtered afterwards, so over-fetch to keep the cap meaningful.
		$sql .= $this->db->plimit($isRegex ? ($limit * 20) : $limit, 0);

		$out = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}

		$re = $isRegex ? $this->buildRegex($rule['match_value']) : '';
		while ($o = $this->db->fetch_object($resql)) {
			if ($isRegex && @preg_match($re, (string) $o->ref) !== 1) {
				continue;
			}
			$out[] = array(
				'id' => (int) $o->rowid,
				'ref' => (string) $o->ref,
				'label' => (string) $o->label,
				'type' => (int) $o->fk_product_type,
			);
			if (count($out) >= $limit) {
				break;
			}
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Preview an entire rule set.
	 *
	 * @param  int $rulesetId Rule set id
	 * @param  int $limit     Maximum rows per rule
	 * @return array<string,mixed> Per-rule matches and a de-duplicated change count
	 */
	public function previewRuleSet($rulesetId, $limit = 200)
	{
		$set = $this->getRuleSet($rulesetId);
		if (!$set) {
			$this->error = 'RuleSetNotFound';
			return array('ok' => false, 'rules' => array(), 'total_changes' => 0, 'products' => 0);
		}

		$perRule = array();
		$pairs = array();
		$productIds = array();

		foreach ($set['rules'] as $rule) {
			$matches = $this->previewRule($rule, !empty($set['only_untagged']), $limit);
			$perRule[] = array(
				'rule' => $rule,
				'matches' => $matches,
				'count' => count($matches),
			);
			foreach ($matches as $m) {
				// One product may be matched by several rules; count distinct
				// product/category pairs, which is what actually gets written.
				$pairs[$m['id'].':'.$rule['category']] = true;
				$productIds[$m['id']] = true;
			}
		}

		return array(
			'ok' => true,
			'ruleset' => array('id' => $set['id'], 'label' => $set['label'], 'only_untagged' => $set['only_untagged']),
			'rules' => $perRule,
			'total_changes' => count($pairs),
			'products' => count($productIds),
		);
	}

	/**
	 * Apply a rule set, writing through the audited curator.
	 *
	 * @param  int  $rulesetId Rule set id
	 * @param  User $user      Acting user
	 * @return array<string,mixed> Outcome from DoliCurateCurator::applyChanges()
	 */
	public function applyRuleSet($rulesetId, $user)
	{
		$curator = new DoliCurateCurator($this->db);
		$set = $this->getRuleSet($rulesetId);

		if (!$set) {
			return array('ok' => false, 'errors' => array('RuleSetNotFound'), 'applied' => 0, 'skipped' => 0, 'failed' => 0, 'batch' => '');
		}

		$limit = $curator->getBatchLimit();
		$changes = array();
		$seen = array();

		foreach ($set['rules'] as $rule) {
			$matches = $this->previewRule($rule, !empty($set['only_untagged']), $limit);
			foreach ($matches as $m) {
				$key = $m['id'].':'.$rule['category'];
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$changes[] = array(
					'product' => (int) $m['id'],
					'category' => (int) $rule['category'],
					'action' => DoliCurateCurator::ACTION_ADD,
				);
			}
		}

		$res = $curator->applyChanges($changes, $user, DoliCurateCurator::SOURCE_RULES, $rulesetId);

		if (!empty($res['ok'])) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."dolicurate_ruleset"
				." SET date_lastrun = '".$this->db->idate(dol_now())."'"
				." WHERE rowid = ".((int) $rulesetId));
		}

		return $res;
	}
}
