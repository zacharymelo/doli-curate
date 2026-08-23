<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/dolicuratecurator.class.php
 * \ingroup dolicurate
 * \brief   The single audited path for every product/category membership change.
 *
 * Nothing else in this module writes to llx_categorie_product. Routing every
 * mutation through here is what makes the audit trail complete, and therefore
 * what makes undo trustworthy.
 *
 * Membership changes always go through Categorie::add_type() / del_type().
 * Product::setCategories() is deliberately never used: it REPLACES a product's
 * entire category set and would silently erase tagging done elsewhere.
 */

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
// Needed for CATEGORY_TYPE_PRODUCT below. Callers that load only this class
// (ajax/assign.php) would otherwise fatal on the constant.
dol_include_once('/dolicurate/class/dolicuratecatalog.class.php');

/**
 * Applies and reverses category membership changes, with an audit trail.
 */
class DoliCurateCurator
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/** Membership was created */
	const ACTION_ADD = 1;

	/** Membership was removed */
	const ACTION_REMOVE = 2;

	/** Change came from the assign screen */
	const SOURCE_MANUAL = 1;

	/** Change came from running a rule set */
	const SOURCE_RULES = 2;

	/** Change came from merging two categories */
	const SOURCE_MERGE = 3;

	/** Change came from undoing an earlier batch */
	const SOURCE_UNDO = 4;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Generate a batch identifier.
	 *
	 * Not security sensitive — it only needs to group rows written together —
	 * but random_bytes() keeps ids from colliding under concurrent use.
	 *
	 * @return string 32-character hex id
	 */
	public function newBatchId()
	{
		try {
			return bin2hex(random_bytes(16));
		} catch (Exception $e) {
			return md5(uniqid((string) mt_rand(), true));
		}
	}

	/**
	 * Ceiling on how many changes one operation may make.
	 *
	 * @return int Maximum changes per batch
	 */
	public function getBatchLimit()
	{
		$limit = getDolGlobalInt('DOLICURATE_BATCH_LIMIT', 2000);

		return ($limit > 0) ? min($limit, 20000) : 2000;
	}

	/**
	 * Apply a set of membership changes as one audited, transactional batch.
	 *
	 * A change is skipped, not failed, when the catalogue is already in the
	 * requested state, which makes re-running any operation harmless.
	 *
	 * @param  array<int,array{product:int,category:int,action:int}> $changes   Requested changes
	 * @param  User                                                  $user      Acting user
	 * @param  int                                                   $source    SOURCE_* constant
	 * @param  int|null                                              $rulesetId Originating rule set, if any
	 * @return array{ok:bool,batch:string,applied:int,skipped:int,failed:int,errors:string[]} Outcome
	 */
	public function applyChanges($changes, $user, $source = self::SOURCE_MANUAL, $rulesetId = null)
	{
		global $conf;

		$out = array('ok' => false, 'batch' => '', 'applied' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array());

		$limit = $this->getBatchLimit();
		if (count($changes) > $limit) {
			$out['errors'][] = 'BatchTooLarge:'.count($changes).'/'.$limit;
			return $out;
		}
		if (empty($changes)) {
			$out['ok'] = true;
			return $out;
		}

		$batch = $this->newBatchId();
		$out['batch'] = $batch;
		$now = $this->db->idate(dol_now());

		$this->db->begin();

		// Cache category and product objects; a batch usually reuses a few.
		$catCache = array();
		$prodCache = array();

		foreach ($changes as $change) {
			$pid = (int) $change['product'];
			$cid = (int) $change['category'];
			$action = (int) $change['action'];

			if ($pid <= 0 || $cid <= 0 || !in_array($action, array(self::ACTION_ADD, self::ACTION_REMOVE), true)) {
				$out['failed']++;
				$out['errors'][] = 'BadChange';
				continue;
			}

			if (!isset($catCache[$cid])) {
				$cat = new Categorie($this->db);
				$catCache[$cid] = ($cat->fetch($cid) > 0) ? $cat : false;
			}
			$cat = $catCache[$cid];
			if ($cat === false) {
				$out['failed']++;
				$out['errors'][] = 'CategoryNotFound:'.$cid;
				continue;
			}

			// Refuse to touch a category that is not a product category.
			if ((int) $cat->type !== DoliCurateCatalog::CATEGORY_TYPE_PRODUCT) {
				$out['failed']++;
				$out['errors'][] = 'NotAProductCategory:'.$cid;
				continue;
			}

			$has = $cat->containsObject('product', $pid);

			if (($action === self::ACTION_ADD && $has) || ($action === self::ACTION_REMOVE && !$has)) {
				$out['skipped']++;
				continue;
			}

			if (!isset($prodCache[$pid])) {
				$p = new Product($this->db);
				$prodCache[$pid] = ($p->fetch($pid) > 0) ? $p : false;
			}
			$product = $prodCache[$pid];
			if ($product === false) {
				$out['failed']++;
				$out['errors'][] = 'ProductNotFound:'.$pid;
				continue;
			}

			$res = ($action === self::ACTION_ADD)
				? $cat->add_type($product, 'product')
				: $cat->del_type($product, 'product');

			if ($res < 0) {
				$out['failed']++;
				$out['errors'][] = ($product->ref ?: $pid).': '.($cat->error ?: 'ChangeFailed');
				continue;
			}

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."dolicurate_log";
			$sql .= " (entity, batch_id, action, source, fk_product, fk_categorie, fk_ruleset, undone, fk_user, date_creation)";
			$sql .= " VALUES (".((int) $conf->entity);
			$sql .= ", '".$this->db->escape($batch)."'";
			$sql .= ", ".$action;
			$sql .= ", ".((int) $source);
			$sql .= ", ".$pid;
			$sql .= ", ".$cid;
			$sql .= ", ".($rulesetId > 0 ? (int) $rulesetId : 'null');
			$sql .= ", 0";
			$sql .= ", ".((int) $user->id);
			$sql .= ", '".$now."')";

			if (!$this->db->query($sql)) {
				// The audit row is not optional: without it the change cannot be
				// undone, so treat a logging failure as a failed change.
				$out['failed']++;
				$out['errors'][] = 'AuditWriteFailed: '.$this->db->lasterror();
				continue;
			}

			$out['applied']++;
		}

		if ($out['failed'] > 0) {
			$this->db->rollback();
			$out['applied'] = 0;
			$out['ok'] = false;
			return $out;
		}

		$this->db->commit();
		$out['ok'] = true;

		return $out;
	}

	/**
	 * Convenience wrapper: put every product into every category.
	 *
	 * @param  int[]    $productIds  Products
	 * @param  int[]    $categoryIds Categories
	 * @param  User     $user        Acting user
	 * @param  int      $source      SOURCE_* constant
	 * @param  int|null $rulesetId   Originating rule set
	 * @return array<string,mixed>   Outcome from applyChanges()
	 */
	public function assign($productIds, $categoryIds, $user, $source = self::SOURCE_MANUAL, $rulesetId = null)
	{
		return $this->applyChanges($this->cross($productIds, $categoryIds, self::ACTION_ADD), $user, $source, $rulesetId);
	}

	/**
	 * Convenience wrapper: remove every product from every category.
	 *
	 * @param  int[] $productIds  Products
	 * @param  int[] $categoryIds Categories
	 * @param  User  $user        Acting user
	 * @param  int   $source      SOURCE_* constant
	 * @return array<string,mixed> Outcome from applyChanges()
	 */
	public function unassign($productIds, $categoryIds, $user, $source = self::SOURCE_MANUAL)
	{
		return $this->applyChanges($this->cross($productIds, $categoryIds, self::ACTION_REMOVE), $user, $source);
	}

	/**
	 * Cartesian product of products and categories as change rows.
	 *
	 * @param  int[] $productIds  Products
	 * @param  int[] $categoryIds Categories
	 * @param  int   $action      ACTION_* constant
	 * @return array<int,array{product:int,category:int,action:int}> Change rows
	 */
	private function cross($productIds, $categoryIds, $action)
	{
		$changes = array();
		foreach ($productIds as $pid) {
			$pid = (int) $pid;
			if ($pid <= 0) {
				continue;
			}
			foreach ($categoryIds as $cid) {
				$cid = (int) $cid;
				if ($cid <= 0) {
					continue;
				}
				$changes[] = array('product' => $pid, 'category' => $cid, 'action' => $action);
			}
		}

		return $changes;
	}

	/**
	 * Reverse a previously applied batch.
	 *
	 * The reversal is itself recorded as a new batch, so the history stays a
	 * forward-only log and an undo can in turn be undone.
	 *
	 * @param  string $batchId Batch to reverse
	 * @param  User   $user    Acting user
	 * @return array<string,mixed> Outcome from applyChanges(), plus 'reversed'
	 */
	public function undoBatch($batchId, $user)
	{
		$out = array('ok' => false, 'batch' => '', 'applied' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array(), 'reversed' => 0);

		$sql = "SELECT rowid, action, fk_product, fk_categorie FROM ".MAIN_DB_PREFIX."dolicurate_log";
		$sql .= " WHERE batch_id = '".$this->db->escape($batchId)."'";
		$sql .= " AND entity IN (".getEntity('dolicurate').")";
		$sql .= " AND undone = 0";
		$sql .= " ORDER BY rowid DESC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$out['errors'][] = $this->db->lasterror();
			return $out;
		}

		$changes = array();
		$logIds = array();
		while ($o = $this->db->fetch_object($resql)) {
			$logIds[] = (int) $o->rowid;
			$changes[] = array(
				'product' => (int) $o->fk_product,
				'category' => (int) $o->fk_categorie,
				// Invert: an add becomes a remove and vice versa.
				'action' => ((int) $o->action === self::ACTION_ADD) ? self::ACTION_REMOVE : self::ACTION_ADD,
			);
		}
		$this->db->free($resql);

		if (empty($changes)) {
			$out['errors'][] = 'NothingToUndo';
			return $out;
		}

		$res = $this->applyChanges($changes, $user, self::SOURCE_UNDO, null);
		$out = array_merge($out, $res);

		if (!$res['ok']) {
			return $out;
		}

		// Mark the original rows so the batch cannot be undone twice.
		if (!empty($logIds)) {
			$upd = "UPDATE ".MAIN_DB_PREFIX."dolicurate_log SET undone = 1";
			$upd .= " WHERE rowid IN (".$this->db->sanitize(implode(',', $logIds)).")";
			$this->db->query($upd);
		}

		$out['reversed'] = count($changes);

		return $out;
	}

	/**
	 * Recent batches, newest first, for the history screen.
	 *
	 * @param  int $limit Maximum batches to return
	 * @return array<int,array<string,mixed>> Batch summaries
	 */
	public function listBatches($limit = 50)
	{
		$limit = max(1, min((int) $limit, 500));

		$sql = "SELECT l.batch_id, l.source, l.fk_ruleset, l.fk_user,";
		$sql .= " MIN(l.date_creation) as started,";
		$sql .= " COUNT(*) as changes,";
		$sql .= " SUM(CASE WHEN l.action = ".self::ACTION_ADD." THEN 1 ELSE 0 END) as adds,";
		$sql .= " SUM(CASE WHEN l.action = ".self::ACTION_REMOVE." THEN 1 ELSE 0 END) as removes,";
		$sql .= " SUM(l.undone) as undone_rows,";
		$sql .= " u.login as user_login";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolicurate_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.entity IN (".getEntity('dolicurate').")";
		$sql .= " GROUP BY l.batch_id, l.source, l.fk_ruleset, l.fk_user, u.login";
		$sql .= " ORDER BY started DESC";
		$sql .= $this->db->plimit($limit, 0);

		$out = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}
		while ($o = $this->db->fetch_object($resql)) {
			$changes = (int) $o->changes;
			$undone = (int) $o->undone_rows;
			$out[] = array(
				'batch' => (string) $o->batch_id,
				'source' => (int) $o->source,
				'ruleset' => (int) $o->fk_ruleset,
				'user' => (string) $o->user_login,
				'started' => $this->db->jdate($o->started),
				'changes' => $changes,
				'adds' => (int) $o->adds,
				'removes' => (int) $o->removes,
				// Fully undone only when every row in the batch is marked.
				'undone' => ($undone >= $changes && $changes > 0) ? 1 : 0,
			);
		}
		$this->db->free($resql);

		$this->decorateBatchSummaries($out);

		return $out;
	}

	/**
	 * Attach a summary of what each batch touched.
	 *
	 * Done as one extra query over the listed batches rather than a
	 * GROUP_CONCAT in the main statement, because GROUP_CONCAT is not portable
	 * to PostgreSQL. Grouping happens in PHP instead.
	 *
	 * @param  array<int,array<string,mixed>> $batches Batches, by reference
	 * @return void
	 */
	private function decorateBatchSummaries(&$batches)
	{
		if (empty($batches)) {
			return;
		}

		$ids = array();
		foreach ($batches as $b) {
			$ids[] = "'".$this->db->escape($b['batch'])."'";
		}

		// Distinct categories touched, and distinct products affected, per batch.
		$sql = "SELECT DISTINCT l.batch_id, l.fk_categorie, l.fk_product, c.label";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolicurate_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = l.fk_categorie";
		$sql .= " WHERE l.batch_id IN (".implode(',', $ids).")";
		$sql .= " AND l.entity IN (".getEntity('dolicurate').")";

		$cats = array();
		$prods = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$bid = (string) $o->batch_id;
				$cid = (int) $o->fk_categorie;
				// A category deleted since (a merge, for instance) has no label.
				$cats[$bid][$cid] = ($o->label !== null && $o->label !== '')
					? (string) $o->label
					: '#'.$cid;
				$prods[$bid][(int) $o->fk_product] = true;
			}
			$this->db->free($resql);
		}

		// Rule set names, so a rules batch says which rule set ran.
		$rulesetIds = array();
		foreach ($batches as $b) {
			if (!empty($b['ruleset'])) {
				$rulesetIds[(int) $b['ruleset']] = true;
			}
		}
		$rulesetNames = array();
		if (!empty($rulesetIds)) {
			$sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."dolicurate_ruleset";
			$sql .= " WHERE rowid IN (".$this->db->sanitize(implode(',', array_keys($rulesetIds))).")";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($o = $this->db->fetch_object($resql)) {
					$rulesetNames[(int) $o->rowid] = (string) $o->label;
				}
				$this->db->free($resql);
			}
		}

		foreach ($batches as $k => $b) {
			$bid = $b['batch'];
			$batches[$k]['categories'] = isset($cats[$bid]) ? array_values($cats[$bid]) : array();
			$batches[$k]['products'] = isset($prods[$bid]) ? count($prods[$bid]) : 0;
			$batches[$k]['ruleset_label'] = (!empty($b['ruleset']) && isset($rulesetNames[(int) $b['ruleset']]))
				? $rulesetNames[(int) $b['ruleset']]
				: '';
		}
	}

	/**
	 * The individual changes inside one batch.
	 *
	 * This is what makes the history usable: a batch summary says three things
	 * were added, this says which three, and to where.
	 *
	 * @param  string $batchId Batch id
	 * @param  int    $limit   Maximum rows
	 * @param  int    $offset  Rows to skip
	 * @return array{rows:array<int,array<string,mixed>>,total:int} Detail rows
	 */
	public function getBatchDetail($batchId, $limit = 200, $offset = 0)
	{
		$out = array('rows' => array(), 'total' => 0);

		if (!preg_match('/^[0-9a-f]{32}$/', (string) $batchId)) {
			$this->error = 'BadBatchId';
			return $out;
		}

		$limit = max(1, min((int) $limit, 1000));
		$offset = max(0, (int) $offset);
		$escaped = $this->db->escape($batchId);

		$resql = $this->db->query("SELECT COUNT(*) c FROM ".MAIN_DB_PREFIX."dolicurate_log"
			." WHERE batch_id = '".$escaped."' AND entity IN (".getEntity('dolicurate').")");
		if ($resql && ($o = $this->db->fetch_object($resql))) {
			$out['total'] = (int) $o->c;
			$this->db->free($resql);
		}

		$sql = "SELECT l.rowid, l.action, l.undone, l.fk_product, l.fk_categorie,";
		$sql .= " p.ref as product_ref, p.label as product_label, p.fk_product_type,";
		$sql .= " c.label as category_label, c.color as category_color";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolicurate_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = l.fk_product";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = l.fk_categorie";
		$sql .= " WHERE l.batch_id = '".$escaped."'";
		$sql .= " AND l.entity IN (".getEntity('dolicurate').")";
		$sql .= " ORDER BY c.label ASC, p.ref ASC, l.rowid ASC";
		$sql .= $this->db->plimit($limit, $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}

		while ($o = $this->db->fetch_object($resql)) {
			// A product or category deleted since the batch ran still has a log
			// row. Show the id rather than a blank, so the record stays honest.
			$out['rows'][] = array(
				'id' => (int) $o->rowid,
				'action' => (int) $o->action,
				'undone' => (int) $o->undone,
				'product' => (int) $o->fk_product,
				'product_ref' => ($o->product_ref !== null) ? (string) $o->product_ref : '#'.((int) $o->fk_product),
				'product_label' => ($o->product_label !== null) ? (string) $o->product_label : '',
				'product_deleted' => ($o->product_ref === null) ? 1 : 0,
				'product_type' => (int) $o->fk_product_type,
				'category' => (int) $o->fk_categorie,
				'category_label' => ($o->category_label !== null) ? (string) $o->category_label : '#'.((int) $o->fk_categorie),
				'category_color' => ($o->category_color !== null) ? (string) $o->category_color : '',
				'category_deleted' => ($o->category_label === null) ? 1 : 0,
			);
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Delete audit rows older than the configured retention window.
	 *
	 * @return int Rows deleted, or -1 on error
	 */
	public function purgeOldLogs()
	{
		$days = getDolGlobalInt('DOLICURATE_KEEP_LOG_DAYS', 90);
		if ($days <= 0) {
			return 0;
		}

		$cutoff = $this->db->idate(dol_time_plus_duree(dol_now(), -$days, 'd'));

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."dolicurate_log";
		$sql .= " WHERE date_creation < '".$cutoff."'";
		$sql .= " AND entity IN (".getEntity('dolicurate').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->affected_rows($resql);
	}
}
